<?php

namespace App\Models;

use App\Notifications\OrderNotifier;
use App\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToStore;

    /** Statuses where the buyer's money was captured (net sales = total − refunded_total). */
    public const PAID_STATUSES = ['paid', 'partially_refunded', 'refunded'];

    public const CANCEL_REASONS = [
        'customer' => '买家要求取消',
        'inventory' => '库存不足',
        'fraud' => '疑似欺诈订单',
        'declined' => '付款失败',
        'other' => '其他原因',
    ];

    protected $fillable = [
        'gift_card_id', 'gift_card_total', 'source', 'subscription_id', 'company_id', 'po_number', 'payment_due_at',
        'store_id', 'number', 'token', 'customer_id', 'email', 'currency', 'locale',
        'subtotal', 'shipping_total', 'discount_code', 'discount_total', 'discount_lines', 'tax_total', 'taxes_included', 'tax_lines', 'total', 'refunded_total',
        'financial_status', 'fulfillment_status', 'shipping_address', 'shipping_method',
        'payment_gateway', 'payment_reference', 'stripe_account', 'application_fee', 'dispute', 'tracking_company', 'tracking_number',
        'note', 'paid_at', 'fulfilled_at', 'review_requested_at', 'delivered_at', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'shipping_method' => 'array',
            'subtotal' => 'integer',
            'shipping_total' => 'integer',
            'discount_total' => 'integer',
            'tax_total' => 'integer',
            'taxes_included' => 'boolean',
            'tax_lines' => 'array',
            'total' => 'integer',
            'refunded_total' => 'integer',
            'paid_at' => 'datetime',
            'application_fee' => 'integer',
            'dispute' => 'array',
            'balance_due' => 'integer',
            'risk_reasons' => 'array',
            'risk_hold' => 'boolean',
            'risk_score' => 'integer',
            'discount_lines' => 'array',
            'recovered' => 'boolean',
            'fulfilled_at' => 'datetime',
            'review_requested_at' => 'datetime',
            'delivered_at' => 'datetime',
            'payment_due_at' => 'datetime',
            'payment_reminded_at' => 'datetime',
            'tracking_events' => 'array',
            'tracking_registered_at' => 'datetime',
            'tracking_synced_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(OrderNotification::class)->latest('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderByDesc('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->latest('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function name(): string
    {
        return '#'.$this->number;
    }

    /** The buyer's money was captured at some point (later refunds don't change that). */
    public function isPaid(): bool
    {
        return in_array($this->financial_status, self::PAID_STATUSES, true);
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** Amount that can still be refunded, in minor units. */
    public function refundable(): int
    {
        return $this->isPaid() ? max(0, $this->total - $this->refunded_total) : 0;
    }

    /** Append a line to the order timeline. */
    public function log(string $type, string $message, array $data = [], ?int $userId = null): OrderEvent
    {
        return OrderEvent::withoutGlobalScope('store')->create([
            'store_id' => $this->store_id,
            'order_id' => $this->id,
            'user_id' => $userId ?? (auth()->hasUser() ? auth()->id() : null),
            'type' => $type,
            'message' => mb_substr($message, 0, 500),
            'data' => $data ?: null,
        ]);
    }

    /** Idempotent: safe to call from both redirects and webhooks. */
    public function markPaid(?string $reference = null): void
    {
        if ($this->financial_status === 'voided') {
            // Paid after the merchant cancelled it (e.g. the buyer kept the Stripe page open).
            // Keep the order cancelled but record the payment so the merchant can refund it.
            $this->forceFill([
                'financial_status' => 'paid',
                'paid_at' => now(),
                'payment_reference' => $reference ?? $this->payment_reference,
            ])->save();
            $this->log('paid', __('已取消的订单收到了付款，请为买家退款'), ['reference' => $reference]);
            app(\App\Billing\FeeLedger::class)->recordPaid($this);

            return;
        }
        if (($this->financial_status ?? 'pending') !== 'pending') {
            return;
        }

        $this->forceFill([
            'financial_status' => 'paid',
            'paid_at' => now(),
            'payment_reference' => $reference ?? $this->payment_reference,
        ])->save();

        $this->customer?->increment('total_spent', $this->total);
        $this->log('paid', __('订单已付款'), $reference ? ['reference' => $reference] : []);
        app(\App\Billing\FeeLedger::class)->recordPaid($this);   // platform transaction fee (paid plans only)

        app(OrderNotifier::class)->orderPaid($this);
        app(\App\GiftCards\GiftCards::class)->issuePurchased($this);   // gift cards bought in this order
        app(\App\Catalog\DigitalDelivery::class)->deliver($this);          // download links for digital products
        app(\App\Marketing\Affiliates::class)->orderPaid($this);           // affiliate commission
        app(\App\Loyalty\Points::class)->earn($this);
        app(\App\Subscriptions\Subscriptions::class)->startFrom($this);
        \App\Marketing\ServerEvents::configured($this->store_id) && \App\Jobs\SendServerEvents::dispatch($this->id);
        app(\App\Marketing\Flows::class)->onOrderPaid($this);   // post-purchase flow
        app(\App\Webhooks\Webhooks::class)->orderEvent($this, 'orders/paid');
    }
}
