<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $a = $this->shipping_address;

        return [
            'id' => $this->id,
            'name' => $this->name(),
            'number' => $this->number,
            'email' => $this->email,
            'customer_name' => trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')),
            'currency' => $this->currency,
            'subtotal' => Money::format($this->subtotal),
            'shipping_total' => Money::format($this->shipping_total),
            'discount_code' => $this->discount_code,
            'discount_total' => Money::format($this->discount_total),
            'discount_lines' => collect($this->discount_lines ?? [])->map(fn ($l) => [...$l, 'amount' => Money::format((int) $l['amount'])])->values(),
            'gift_card_total' => Money::format((int) $this->gift_card_total),
            'tax_total' => Money::format($this->tax_total),
            'duty_total' => Money::format((int) $this->duty_total),   // import duties charged at checkout (market orders)
            'payment_fee' => Money::format((int) $this->payment_fee),   // cash-on-delivery fee
            'taxes_included' => (bool) $this->taxes_included,
            'tax_lines' => $this->tax_lines ?? [],
            'total' => Money::format($this->total),
            'refunded_total' => Money::format($this->refunded_total),
            'net_total' => Money::format($this->total - $this->refunded_total),
            'balance_due' => Money::format((int) $this->balance_due),
            'edits_count' => (int) $this->edits_count,
            'edit_blocker' => $this->whenLoaded('items', fn () => app(\App\Orders\OrderEditor::class)->blocker($this->resource)),
            'refundable' => Money::format($this->refundable()),
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
            'cancel_reason_label' => $this->cancel_reason ? __(\App\Models\Order::CANCEL_REASONS[$this->cancel_reason] ?? $this->cancel_reason) : null,
            'financial_status' => $this->financial_status,
            'dispute' => $this->dispute ? [
                ...$this->dispute,
                'reason_label' => isset($this->dispute['reason']) ? __(\App\Payments\StripeDisputes::REASONS[$this->dispute['reason']] ?? $this->dispute['reason']) : null,
            ] : null,
            'fulfillment_status' => $this->fulfillment_status,
            'risk_level' => $this->risk_level,
            'risk_score' => $this->risk_score,
            'risk_hold' => (bool) $this->risk_hold,
            'risk_reasons' => collect($this->risk_reasons ?? [])->map(fn ($r) => [
                'code' => $r['code'], 'points' => $r['points'], 'label' => \App\Risk\RiskAnalyzer::describe($r, $this->currency),
            ])->values(),
            'client_ip' => $this->client_ip,
            'client_country' => $this->client_country,
            'payment_gateway' => $this->payment_gateway,
            'source' => $this->source ?? 'web',
            'payment_due_at' => $this->payment_due_at,
            'po_number' => $this->po_number,
            'company' => $this->company_id ? \App\Models\Company::find($this->company_id, ['id', 'name'])?->only('id', 'name') : null,
            'payment_reference' => $this->payment_reference,
            'shipping_address' => $a,
            'shipping_method' => $this->shipping_method,
            'tracking_company' => $this->tracking_company,
            'tracking_number' => $this->tracking_number,
            'tracking_status' => $this->tracking_status,
            'tracking_carrier' => $this->tracking_carrier,
            'tracking_events' => $this->tracking_events ?? [],
            'tracking_synced_at' => $this->tracking_synced_at,
            'tracking_enabled' => app(\App\Shipping\PackageTracking::class)->enabled(),
            'delivered_at' => $this->delivered_at,
            'note' => $this->note,
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'title' => $i->title,
                'variant_title' => $i->variant_title,
                'sku' => $i->sku,
                'image' => $i->image,
                'price' => Money::format($i->price),
                'quantity' => $i->quantity,
                'refunded_quantity' => $i->refunded_quantity,
                'total' => Money::format($i->total),
                'preorder' => (bool) $i->preorder,
                'gift' => $i->gift,   // gift card recipient {email, name, message}
            ])),
            'downloads' => $this->whenLoaded('items', fn () => \App\Catalog\DigitalDelivery::links($this->resource, '')),
            // Refund form needs these to suggest an amount (discount spread over items, shipping left)
            'refund_info' => $this->whenLoaded('refunds', fn () => [
                'shipping_refundable' => Money::format($this->shipping_total - (int) $this->refunds->sum('shipping_amount')),
                'refunds_online' => (bool) app(\App\Payments\GatewayRegistry::class)->all()->get($this->payment_gateway)?->refundsOnline(),
            ]),
            'refunds' => $this->whenLoaded('refunds', fn () => $this->refunds->map(fn ($r) => [
                'id' => $r->id,
                'amount' => Money::format($r->amount),
                'shipping_amount' => Money::format($r->shipping_amount),
                'reason' => $r->reason,
                'restock' => $r->restock,
                'reference' => $r->reference,
                'user' => $r->user?->name,
                'created_at' => $r->created_at,
            ])),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'message' => __($e->message),   // stored text; standard messages shown in the admin's language
                'user' => $e->user?->name,
                'created_at' => $e->created_at,
            ])),
            'notifications' => $this->whenLoaded('notifications', fn () => $this->notifications->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'recipient' => $n->recipient,
                'status' => $n->status,
                'error' => $n->error,
                'sent_at' => $n->sent_at,
                'created_at' => $n->created_at,
            ])),
            'paid_at' => $this->paid_at,
            'fulfilled_at' => $this->fulfilled_at,
            'created_at' => $this->created_at,
        ];
    }
}
