<?php

namespace App\Payments;

use App\Billing\BillingMailer;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Card disputes (chargebacks) from the charge.dispute.* webhooks.
 *
 * Saved on the order (orders.dispute) and shown in the admin; the store owner gets an email.
 * With Stripe Connect the dispute lands on the platform (destination charge), so when it opens
 * we reverse the disputed amount from the store's transfer — the merchant carries the
 * chargeback, like with any payment processor — and send it back if the dispute is won.
 */
class StripeDisputes
{
    /** https://docs.stripe.com/api/disputes/object#dispute_object-reason */
    public const REASONS = [
        'fraudulent' => '买家称这笔付款不是本人操作',
        'product_not_received' => '买家称没有收到商品',
        'product_unacceptable' => '买家称商品与描述不符或有质量问题',
        'duplicate' => '买家称重复扣款',
        'credit_not_processed' => '买家称应退的款没有退',
        'subscription_canceled' => '买家称已取消订阅',
        'unrecognized' => '买家不认识这笔付款',
        'general' => '其他原因',
    ];

    public function handle(array $event): void
    {
        $d = $event['data']['object'] ?? [];
        $order = $this->order($d);
        if (! $order) {
            return;
        }

        $before = $order->dispute ?? [];
        $dispute = [
            ...$before,
            'id' => $d['id'],
            'status' => $d['status'] ?? null,
            'reason' => $d['reason'] ?? null,
            'amount' => (int) ($d['amount'] ?? 0),
            'currency' => strtoupper((string) ($d['currency'] ?? $order->currency)),
            'due_by' => isset($d['evidence_details']['due_by']) ? date(DATE_ATOM, (int) $d['evidence_details']['due_by']) : ($before['due_by'] ?? null),
            'opened_at' => $before['opened_at'] ?? now()->toIso8601String(),
        ];
        $type = $event['type'];

        if ($type === 'charge.dispute.created' && ($before['id'] ?? null) !== $d['id']) {
            $order->forceFill(['dispute' => $dispute])->save();
            $order->log('dispute', __('买家对这笔付款提出了争议（拒付），金额 :p0', ['p0' => \App\Support\Money::format($dispute['amount']).' '.$dispute['currency']]), ['dispute' => $d['id'], 'reason' => $dispute['reason']]);
            if ($order->stripe_account) {
                $dispute = [...$dispute, ...$this->reverse($order, $d)];
            }
            $order->forceFill(['dispute' => $dispute])->save();
            BillingMailer::notify($order->store, 'dispute_opened', $order);

            return;
        }

        if ($type === 'charge.dispute.closed' && ! isset($before['closed_at'])) {
            $dispute['closed_at'] = now()->toIso8601String();
            $won = $dispute['status'] === 'won';
            if ($won && ! empty($before['reversal_id']) && empty($before['returned_transfer_id'])) {
                $dispute['returned_transfer_id'] = $this->giveBack($order, $d, (int) ($before['reversed_amount'] ?? $dispute['amount']));
            }
            $order->forceFill(['dispute' => $dispute])->save();
            $order->log('dispute', $won ? __('争议已结束：商家胜诉，款项已退回') : __('争议已结束：买家胜诉，款项已退给买家'), ['dispute' => $d['id'], 'status' => $dispute['status']]);
            BillingMailer::notify($order->store, 'dispute_closed', $order);

            return;
        }

        // charge.dispute.updated / funds_withdrawn / funds_reinstated: keep the status current
        $order->forceFill(['dispute' => $dispute])->save();
    }

    /** Orders store the PaymentIntent id once paid. */
    private function order(array $d): ?Order
    {
        $intent = $d['payment_intent'] ?? null;
        if (! is_string($intent) || $intent === '') {
            return null;
        }

        return Order::withoutGlobalScope('store')->with('store')
            ->where('payment_gateway', 'stripe')->where('payment_reference', $intent)->first();
    }

    /** Take the disputed amount back from the store's connected account. @return array{reversal_id?:string, reversed_amount?:int, transfer_id?:string} */
    private function reverse(Order $order, array $d): array
    {
        try {
            $transfer = $this->api()->get("https://api.stripe.com/v1/charges/{$d['charge']}")->json('transfer');
            if (! is_string($transfer) || $transfer === '') {
                throw new RuntimeException('charge has no transfer');
            }
            // Never more than what was transferred (total minus our application fee)
            $amount = min((int) $d['amount'], (int) $order->total - (int) $order->application_fee);
            $response = $this->api()->withHeaders(['Idempotency-Key' => "dispute-reversal-{$d['id']}"])
                ->post("https://api.stripe.com/v1/transfers/{$transfer}/reversals", [
                    'amount' => $amount,
                    'metadata[dispute]' => $d['id'],
                    'metadata[order_id]' => $order->id,
                ]);
            if ($response->failed()) {
                throw new RuntimeException($response->body());
            }
            return ['reversal_id' => $response->json('id'), 'reversed_amount' => $amount, 'transfer_id' => $transfer];
        } catch (RuntimeException $e) {
            report(new RuntimeException("Dispute reversal failed for order {$order->id}: ".$e->getMessage()));
            $order->log('dispute', __('无法自动从商家账户扣回争议金额，请运营人员手动处理'));

            return [];
        }
    }

    /** Dispute won: transfer the reversed amount back to the store. */
    private function giveBack(Order $order, array $d, int $amount): ?string
    {
        $response = $this->api()->withHeaders(['Idempotency-Key' => "dispute-won-{$d['id']}"])
            ->post('https://api.stripe.com/v1/transfers', [
                'amount' => $amount,
                'currency' => strtolower($order->currency),
                'destination' => $order->stripe_account,
                'source_transaction' => $d['charge'],
                'metadata[dispute]' => $d['id'],
                'metadata[order_id]' => $order->id,
            ]);
        if ($response->failed()) {
            report(new RuntimeException("Dispute give-back failed for order {$order->id}: ".$response->body()));

            return null;
        }

        return $response->json('id');
    }

    private function api(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::asForm()->withToken((string) config('services.stripe.secret'))->timeout(20);
    }
}
