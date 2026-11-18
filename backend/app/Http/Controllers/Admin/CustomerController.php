<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Csv;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Customer list (search, tags, segments), profile with order history, tags and notes, CSV export. */
class CustomerController extends Controller
{
    public const MAX_TAGS = 20;

    public function index(Request $request): JsonResponse
    {
        $sort = match ($request->query('sort')) {
            'spent' => ['total_spent', 'desc'],
            'orders' => ['orders_count', 'desc'],
            'oldest' => ['id', 'asc'],
            default => ['id', 'desc'],
        };
        $page = $this->filtered($request)->orderBy(...$sort)
            ->paginate(min((int) $request->query('per_page', 20), 100));

        $page->getCollection()->transform(fn (Customer $c) => $this->row($c));

        return response()->json($page);
    }

    /** Every tag in use, for the filter and the tag picker. */
    public function tags(): JsonResponse
    {
        $tags = Customer::whereNotNull('tags')->pluck('tags')->flatten()->filter()
            ->countBy()->sortKeys()->map(fn ($n, $tag) => ['tag' => $tag, 'count' => $n])->values();

        return response()->json(['data' => $tags]);
    }

    public function show(int $id): JsonResponse
    {
        $c = Customer::findOrFail($id);
        $orders = Order::where('customer_id', $c->id)->orWhere(fn ($q) => $q->whereNull('customer_id')->where('email', $c->email))
            ->withCount('items')->latest('id')->limit(10)->get();
        $paid = Order::where('customer_id', $c->id)->whereIn('financial_status', Order::PAID_STATUSES)->whereNull('cancelled_at');
        $first = (clone $paid)->min('created_at');
        $last = (clone $paid)->max('created_at');

        return response()->json(['data' => [
            ...$this->row($c),
            'first_name' => $c->first_name,
            'last_name' => $c->last_name,
            'note' => $c->note,
            'default_address' => $c->default_address,
            'marketing_source' => $c->marketing_source,
            'marketing_updated_at' => $c->marketing_updated_at,
            'last_login_at' => $c->last_login_at,
            'aov' => Money::format($c->orders_count ? intdiv((int) $c->total_spent, max(1, $c->orders_count)) : 0),
            'first_order_at' => $first,
            'tax_exempt' => (bool) $c->tax_exempt,
            'birthday' => $c->birthday?->toDateString(),
            'company' => $c->company_id ? \App\Models\Company::find($c->company_id, ['id', 'name', 'payment_terms', 'tax_exempt'])?->toArray() : null,
            'last_order_at' => $last,
            'orders' => OrderResource::collection($orders),
        ]]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $c = Customer::findOrFail($id);
        $data = $request->validate([
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'tags' => ['sometimes', 'array', 'max:'.self::MAX_TAGS],
            'tags.*' => ['string', 'max:40'],
            'accepts_marketing' => ['sometimes', 'boolean'],
            'tax_exempt' => ['sometimes', 'boolean'],
            'birthday' => ['sometimes', 'nullable', 'date', 'before:today'],
        ]);
        if (array_key_exists('tags', $data)) {
            $data['tags'] = self::cleanTags($data['tags']);
        }
        if (array_key_exists('accepts_marketing', $data)) {
            $c->setMarketingConsent((bool) $data['accepts_marketing'], 'admin');
            unset($data['accepts_marketing']);
        }
        $c->forceFill($data)->save();

        return $this->show($c->id);
    }

    /** Add or remove one tag on many customers at once (list bulk action). */
    public function bulkTags(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'tag' => ['required', 'string', 'max:40'],
            'action' => ['required', Rule::in(['add', 'remove'])],
        ]);
        $tag = self::cleanTags([$data['tag']])[0] ?? null;
        abort_unless($tag, 422);
        $n = 0;
        foreach (Customer::whereIn('id', $data['ids'])->get() as $c) {
            $tags = $c->tags ?? [];
            $next = $data['action'] === 'add'
                ? self::cleanTags([...$tags, $tag])
                : array_values(array_filter($tags, fn ($t) => mb_strtolower($t) !== mb_strtolower($tag)));
            if ($next !== $tags) {
                $c->forceFill(['tags' => $next ?: null])->save();
                $n++;
            }
        }

        return response()->json(['updated' => $n]);
    }

    public function export(Request $request): StreamedResponse
    {
        $q = $this->filtered($request);
        $rows = function () use ($q) {
            foreach ($q->lazyById(500) as $c) {
                $a = $c->default_address ?? [];
                yield [
                    $c->first_name, $c->last_name, $c->email, $c->phone, $c->accepts_marketing,
                    $a['address1'] ?? null, $a['city'] ?? null, $a['province'] ?? null, $a['zip'] ?? null, $a['country'] ?? null,
                    $c->orders_count, Money::format($c->total_spent), implode(', ', $c->tags ?? []), $c->note, $c->created_at?->toDateTimeString(),
                ];
            }
        };

        return Csv::download('customers-'.now()->format('Ymd-His').'.csv', [
            'First Name', 'Last Name', 'Email', 'Phone', 'Accepts Email Marketing',
            'Address1', 'City', 'Province', 'Zip', 'Country', 'Total Orders', 'Total Spent', 'Tags', 'Note', 'Created at',
        ], $rows());
    }

    /** Search + segment filters shared by the list and the export. */
    private function filtered(Request $request): Builder
    {
        return Customer::query()
            ->when($request->query('q'), function ($q, $t) {
                $like = '%'.addcslashes($t, '%_\\').'%';
                $q->where(fn ($w) => $w->where('email', 'like', $like)->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)->orWhere('phone', 'like', $like));
            })
            ->when($request->query('tag'), fn ($q, $tag) => $q->whereJsonContains('tags', (string) $tag))
            ->when($request->query('marketing') === 'subscribed', fn ($q) => $q->where('accepts_marketing', true))
            ->when($request->query('marketing') === 'not_subscribed', fn ($q) => $q->where('accepts_marketing', false))
            ->when($request->query('orders') === 'none', fn ($q) => $q->where('orders_count', 0))
            ->when($request->query('orders') === 'one', fn ($q) => $q->where('orders_count', 1))
            ->when($request->query('orders') === 'repeat', fn ($q) => $q->where('orders_count', '>=', 2))
            ->when($request->query('account') === 'registered', fn ($q) => $q->whereNotNull('password'))
            ->when($request->query('account') === 'guest', fn ($q) => $q->whereNull('password'))
            ->when(is_numeric($request->query('min_spent')), fn ($q) => $q->where('total_spent', '>=', Money::toMinor($request->query('min_spent'))));
    }

    private function row(Customer $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->fullName(),
            'email' => $c->email,
            'phone' => $c->phone,
            'orders_count' => $c->orders_count,
            'total_spent' => Money::format($c->total_spent),
            // none = guest checkout only; invited = account not yet confirmed; active = confirmed email
            'account' => ! $c->hasAccount() ? 'none' : ($c->isVerified() ? 'active' : 'unverified'),
            'accepts_marketing' => $c->accepts_marketing,
            'tags' => $c->tags ?? [],
            'created_at' => $c->created_at,
        ];
    }

    /** Trimmed, de-duplicated (case-insensitive), no commas, max 20. */
    public static function cleanTags(array $tags): array
    {
        $out = [];
        foreach ($tags as $t) {
            $t = Str::limit(trim(preg_replace('/[\s,]+/u', ' ', (string) $t)), 40, '');
            if ($t !== '' && ! in_array(mb_strtolower($t), array_map('mb_strtolower', $out), true)) {
                $out[] = $t;
            }
        }

        return array_slice($out, 0, self::MAX_TAGS);
    }
}
