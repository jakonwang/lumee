<?php

namespace App\Http\Controllers\Admin;

use App\B2B\Applications;
use App\B2B\B2B;
use App\Http\Controllers\Controller;
use App\Models\B2bApplication;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Countries;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Customers › Companies: business buyers, their people and locations, terms, tax status; wholesale applications; B2B settings. */
class CompanyController extends Controller
{
    public function index(Request $request, CurrentStore $current): JsonResponse
    {
        $page = Company::withCount('customers')
            ->when($request->query('q'), fn ($q, $t) => $q->where('name', 'like', '%'.addcslashes($t, '%_\\').'%'))
            ->latest('id')->paginate(20);
        $outstanding = Order::where('payment_gateway', 'terms')->where('financial_status', 'pending')->whereNull('cancelled_at')
            ->selectRaw('company_id, COUNT(*) as n, SUM(total) as amount, SUM(CASE WHEN payment_due_at < ? THEN 1 ELSE 0 END) as overdue', [now()])
            ->groupBy('company_id')->get()->keyBy('company_id');
        $page->getCollection()->transform(fn (Company $c) => [...$this->row($c), 'customers_count' => $c->customers_count,
            'open_invoices' => (int) ($outstanding[$c->id]->n ?? 0), 'overdue' => (int) ($outstanding[$c->id]->overdue ?? 0),
            'outstanding' => Money::format((int) ($outstanding[$c->id]->amount ?? 0))]);

        return response()->json([...$page->toArray(),
            'settings' => B2B::settings($current->getOrFail()),
            'pending_applications' => B2bApplication::where('status', 'pending')->count(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $c = Company::findOrFail($id);
        $orders = Order::where('company_id', $c->id)->latest('id')->limit(20)->get();

        return response()->json(['data' => [
            ...$this->row($c), 'note' => $c->note, 'locations' => $c->locations ?? [],
            'customers' => $c->customers()->get(['id', 'email', 'first_name', 'last_name', 'company_role', 'password'])
                ->map(fn ($m) => ['id' => $m->id, 'email' => $m->email, 'name' => $m->fullName(), 'role' => $m->company_role ?? 'buyer', 'has_account' => $m->hasAccount()]),
            'orders' => $orders->map(fn ($o) => ['id' => $o->id, 'name' => $o->name(), 'total' => Money::format($o->total), 'currency' => $o->currency,
                'financial_status' => $o->financial_status, 'payment_gateway' => $o->payment_gateway, 'payment_due_at' => $o->payment_due_at,
                'overdue' => $o->payment_gateway === 'terms' && $o->financial_status === 'pending' && $o->payment_due_at?->isPast(), 'po_number' => $o->po_number, 'created_at' => $o->created_at]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $c = Company::create($this->validated($request));

        return response()->json(['data' => $this->row($c)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        Company::findOrFail($id)->update($this->validated($request));

        return $this->show($id);
    }

    public function destroy(int $id): JsonResponse
    {
        $c = Company::findOrFail($id);
        Customer::where('company_id', $c->id)->update(['company_id' => null, 'company_role' => null]);
        $c->delete();

        return response()->json(null, 204);
    }

    /** Add a buyer by email (creates the customer if needed). */
    public function addMember(Request $request, int $id): JsonResponse
    {
        $c = Company::findOrFail($id);
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'role' => ['nullable', Rule::in(['admin', 'buyer'])],
            'first_name' => ['nullable', 'string', 'max:100'], 'last_name' => ['nullable', 'string', 'max:100']]);
        $m = Customer::firstOrNew(['email' => Str::lower($data['email'])]);
        if ($m->exists && $m->company_id && $m->company_id !== $c->id) {
            return response()->json(['message' => __('这位客户已经属于另一家公司')], 422);
        }
        $m->fill(array_filter(['first_name' => $data['first_name'] ?? null, 'last_name' => $data['last_name'] ?? null]));
        $m->forceFill(['company_id' => $c->id, 'company_role' => $data['role'] ?? 'buyer'])->save();

        return $this->show($c->id);
    }

    public function removeMember(int $id, int $customerId): JsonResponse
    {
        Customer::where('company_id', $id)->whereKey($customerId)->update(['company_id' => null, 'company_role' => null]);

        return $this->show($id);
    }

    // ── Wholesale applications ──

    public function applications(Request $request): JsonResponse
    {
        return response()->json(B2bApplication::when($request->query('status', 'pending'), fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s))
            ->latest('id')->paginate(20));
    }

    public function approve(Request $request, int $id, Applications $applications): JsonResponse
    {
        $data = $request->validate(['payment_terms' => ['nullable', 'integer', 'min:0', 'max:365'], 'tax_exempt' => ['boolean'], 'tag' => ['nullable', 'string', 'max:40']]);
        $company = $applications->approve(B2bApplication::findOrFail($id), array_filter($data, fn ($v) => $v !== null));

        return response()->json(['company_id' => $company->id], 201);
    }

    public function reject(Request $request, int $id, Applications $applications): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $applications->reject(B2bApplication::findOrFail($id), $data['reason'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function settings(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'hide_prices' => ['required', 'boolean'], 'applications' => ['required', 'boolean'],
            'tag' => ['required', 'string', 'max:40'], 'terms' => ['required', 'integer', 'min:0', 'max:365'],
        ]);
        $store = $current->getOrFail();
        $store->settings = [...($store->settings ?? []), 'b2b' => [...$data, 'tag' => CustomerController::cleanTags([$data['tag']])[0] ?? 'wholesale']];
        $store->save();

        return response()->json(['settings' => B2B::settings($store), 'apply_url' => $store->storefrontUrl().'/wholesale']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'tax_id' => ['nullable', 'string', 'max:60'],
            'tag' => ['nullable', 'string', 'max:40'],
            'payment_terms' => ['nullable', 'integer', 'min:0', 'max:365'],
            'tax_exempt' => ['boolean'],
            'note' => ['nullable', 'string', 'max:5000'],
            'locations' => ['array', 'max:50'],
            'locations.*.name' => ['nullable', 'string', 'max:100'],
            'locations.*.first_name' => ['nullable', 'string', 'max:100'],
            'locations.*.last_name' => ['nullable', 'string', 'max:100'],
            'locations.*.address1' => ['required', 'string', 'max:200'],
            'locations.*.address2' => ['nullable', 'string', 'max:200'],
            'locations.*.city' => ['required', 'string', 'max:100'],
            'locations.*.province' => ['nullable', 'string', 'max:100'],
            'locations.*.zip' => ['nullable', 'string', 'max:20'],
            'locations.*.country' => ['required', Rule::in(array_keys(Countries::all()))],
            'locations.*.phone' => ['nullable', 'string', 'max:40'],
        ]);
        $data['tag'] = CustomerController::cleanTags([$data['tag'] ?? 'wholesale'])[0] ?? 'wholesale';
        $data['payment_terms'] = ($data['payment_terms'] ?? 0) ?: null;

        return $data;
    }

    private function row(Company $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'tax_id' => $c->tax_id, 'tag' => $c->tag, 'payment_terms' => $c->payment_terms,
            'tax_exempt' => $c->tax_exempt, 'locations_count' => count($c->locations ?? []), 'created_at' => $c->created_at];
    }
}
