<?php

namespace App\Http\Controllers\Admin;

use App\GiftCards\GiftCards;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\Order;
use App\Notifications\GiftCardMail;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Gift cards (Products › Gift cards). The full code is shown once, when the card is created;
 * afterwards only the last 4 characters (the recipient gets it by email).
 */
class GiftCardController extends Controller
{
    public function __construct(private GiftCards $cards) {}

    public function index(Request $request): JsonResponse
    {
        $page = GiftCard::query()
            ->when($request->query('q'), function ($q, $t) {
                $code = GiftCard::normalize($t);
                $q->where(fn ($w) => $w->where('last4', strtoupper(substr($code, -4)))->orWhere('code', $code)
                    ->orWhere('recipient_email', 'like', '%'.addcslashes($t, '%_\\').'%'));
            })
            ->when($request->query('status') === 'active', fn ($q) => $q->whereNull('disabled_at')->where('balance', '>', 0)
                ->where(fn ($w) => $w->whereNull('expires_on')->orWhere('expires_on', '>=', today())))
            ->when($request->query('status') === 'used_up', fn ($q) => $q->where('balance', 0))
            ->when($request->query('status') === 'disabled', fn ($q) => $q->whereNotNull('disabled_at'))
            ->when($request->query('status') === 'expired', fn ($q) => $q->whereNotNull('expires_on')->where('expires_on', '<', today()))
            ->latest('id')->paginate(min((int) $request->query('per_page', 20), 100));
        $page->getCollection()->transform(fn (GiftCard $g) => $this->row($g));

        $totals = GiftCard::whereNull('disabled_at')->selectRaw('COUNT(*) as n, COALESCE(SUM(balance),0) as outstanding')->first();

        return response()->json([...$page->toArray(), 'summary' => [
            'count' => (int) $totals->n, 'outstanding' => Money::format((int) $totals->outstanding),
        ]]);
    }

    public function store(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'value' => ['required', 'numeric', 'min:1', 'max:100000'],
            'code' => ['nullable', 'string', 'max:40'],
            'recipient_email' => ['nullable', 'email:rfc', 'max:255', 'required_if:send,true'],
            'recipient_name' => ['nullable', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
            'expires_on' => ['nullable', 'date', 'after:today'],
            'send' => ['boolean'],
        ]);
        $store = $current->getOrFail();
        $customer = ! empty($data['recipient_email']) ? Customer::where('email', strtolower($data['recipient_email']))->first() : null;

        $card = $this->cards->issue([
            'currency' => $store->currency,
            'customer_id' => $customer?->id,
            'recipient_email' => isset($data['recipient_email']) ? strtolower($data['recipient_email']) : null,
            'recipient_name' => $data['recipient_name'] ?? null,
            'message' => $data['message'] ?? null,
            'note' => $data['note'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
        ], Money::toMinor($data['value']), $data['code'] ?? null);

        if (($data['send'] ?? false) && $card->recipient_email) {
            $this->send($card);
        }

        return response()->json(['data' => [...$this->row($card->fresh()), 'code' => $card->formattedCode()]], 201);
    }

    public function show(int $id): JsonResponse
    {
        $g = GiftCard::with('transactions.user:id,name')->findOrFail($id);
        $orders = Order::whereIn('id', $g->transactions->pluck('order_id')->filter())->get(['id', 'number'])->keyBy('id');

        return response()->json(['data' => [
            ...$this->row($g),
            'message' => $g->message,
            'note' => $g->note,
            'transactions' => $g->transactions->map(fn ($t) => [
                'id' => $t->id, 'kind' => $t->kind, 'amount' => Money::format($t->amount), 'balance_after' => Money::format($t->balance_after),
                'order' => $t->order_id && isset($orders[$t->order_id]) ? ['id' => $t->order_id, 'name' => $orders[$t->order_id]->name()] : null,
                'user' => $t->user?->name, 'note' => $t->note, 'created_at' => $t->created_at,
            ]),
        ]]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $g = GiftCard::findOrFail($id);
        $data = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'expires_on' => ['sometimes', 'nullable', 'date'],
            'recipient_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'recipient_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'disabled' => ['sometimes', 'boolean'],
            'balance' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        if (array_key_exists('balance', $data)) {
            $this->cards->adjust($g, Money::toMinor($data['balance']), $data['reason'] ?? null);
        }
        if (array_key_exists('disabled', $data)) {
            $g->disabled_at = $data['disabled'] ? ($g->disabled_at ?? now()) : null;
        }
        $g->fill(collect($data)->only('note', 'expires_on', 'recipient_name')->all());
        if (array_key_exists('recipient_email', $data)) {
            $g->recipient_email = $data['recipient_email'] ? strtolower($data['recipient_email']) : null;
        }
        $g->save();

        return $this->show($g->id);
    }

    public function resend(int $id): JsonResponse
    {
        $g = GiftCard::findOrFail($id);
        abort_unless($g->recipient_email, 422, __('这张礼品卡没有收件人邮箱'));
        abort_unless($g->usable(), 422, __('这张礼品卡已不可用'));
        $this->send($g);

        return response()->json(['sent_at' => $g->fresh()->sent_at]);
    }

    private function send(GiftCard $g): void
    {
        $store = app(CurrentStore::class)->getOrFail();
        Mail::to($g->recipient_email)->send(new GiftCardMail($g, $store));
        $g->forceFill(['sent_at' => now()])->save();
    }

    private function row(GiftCard $g): array
    {
        return [
            'id' => $g->id,
            'last4' => $g->last4,
            'masked' => '•••• '.$g->last4,
            'status' => $g->status(),
            'initial_value' => Money::format($g->initial_value),
            'balance' => Money::format($g->balance),
            'currency' => $g->currency,
            'recipient_email' => $g->recipient_email,
            'recipient_name' => $g->recipient_name,
            'customer_id' => $g->customer_id,
            'expires_on' => $g->expires_on?->toDateString(),
            'sent_at' => $g->sent_at,
            'created_at' => $g->created_at,
        ];
    }
}
