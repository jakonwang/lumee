<?php

namespace App\Http\Controllers\Admin;

use App\Customers\Privacy;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PrivacyRequest;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Customer data requests: export a customer's data, erase it, and work through requests from customers. */
class PrivacyController extends Controller
{
    public function index(CurrentStore $current, Privacy $privacy): JsonResponse
    {
        $store = $current->getOrFail();
        $rows = PrivacyRequest::latest('id')->limit(200)->get();

        return response()->json([
            'data' => $rows->map(fn (PrivacyRequest $r) => [
                'id' => $r->id, 'email' => $r->email, 'type' => $r->type, 'source' => $r->source, 'status' => $r->status, 'note' => $r->note,
                'created_at' => $r->created_at, 'completed_at' => $r->completed_at,
                'due_at' => $r->created_at->copy()->addDays(30),   // GDPR: one month to respond
                'blocker' => $r->status === 'pending' && $r->type === 'erase' ? $privacy->blocker($store, $r->email) : null,
            ]),
            'pending' => $rows->where('status', 'pending')->count(),
        ]);
    }

    public function complete(int $id, Privacy $privacy): JsonResponse
    {
        $r = PrivacyRequest::where('status', 'pending')->findOrFail($id);
        $email = $r->email;
        $privacy->complete($r);
        if ($r->type === 'erase') {
            $this->confirmErased($email);
        }

        return response()->json(['ok' => true]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:300']], ['note.required' => __('请填写原因')]);
        PrivacyRequest::where('status', 'pending')->findOrFail($id)->forceFill(['status' => 'rejected', 'note' => $data['note'], 'completed_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    public function export(int $id, CurrentStore $current, Privacy $privacy): StreamedResponse
    {
        $c = Customer::findOrFail($id);
        $data = $privacy->export($current->getOrFail(), $c->email);
        PrivacyRequest::create(['store_id' => $current->id(), 'customer_id' => $c->id, 'email' => $c->email, 'type' => 'export',
            'source' => 'merchant', 'status' => 'completed', 'completed_at' => now()]);

        return response()->streamDownload(fn () => print(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'customer-'.$c->id.'-data.json', ['Content-Type' => 'application/json']);
    }

    /** The merchant erases a customer directly (e.g. after a request by email). */
    public function erase(Request $request, int $id, CurrentStore $current, Privacy $privacy): JsonResponse
    {
        $c = Customer::findOrFail($id);
        if ($request->input('confirm') !== $c->email) {
            throw ValidationException::withMessages(['confirm' => __('请输入客户邮箱确认删除')]);
        }
        $r = PrivacyRequest::create(['store_id' => $current->id(), 'customer_id' => $c->id, 'email' => $c->email, 'type' => 'erase', 'source' => 'merchant', 'status' => 'pending']);
        try {
            $privacy->complete($r);
        } catch (ValidationException $e) {
            $r->delete();
            throw $e;
        }
        PrivacyRequest::where('email', $c->email)->where('type', 'erase')->where('status', 'pending')->update(['status' => 'completed', 'completed_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Last email to the address: their data is gone. */
    private function confirmErased(string $email): void
    {
        rescue(function () use ($email) {
            $store = app(CurrentStore::class)->getOrFail();
            Mail::raw(__('Your personal data at :store has been deleted, as you asked. Your order records were anonymized. You can no longer sign in to your account.', ['store' => $store->name]),
                fn ($m) => $m->to($email)->subject(__('Your account at :store has been deleted', ['store' => $store->name])));
        });
    }
}
