<?php

namespace App\Billing;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/** Platform → merchant billing emails (Lumée-branded, Chinese, to the store owner). */
class BillingMailer extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const SUBJECTS = [
        'trial_ending' => '你的免费试用即将结束',
        'payment_failed' => '套餐续费扣款失败',
        'dispute_opened' => '订单 :p0 收到了拒付（争议）',
        'dispute_closed' => '订单 :p0 的争议已结束',
    ];

    public function __construct(public Store $store, public string $type, public ?\App\Models\Order $order = null) {}

    public static function notify(Store $store, string $type, ?\App\Models\Order $order = null): void
    {
        $owner = $store->owner;
        if ($owner?->email) {
            Mail::to($owner->email)->locale($owner->locale ?: 'zh_CN')->queue(new self($store, $type, $order));
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '【'.$this->store->name.'】'.__(self::SUBJECTS[$this->type], ['p0' => $this->order?->name()]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.billing', with: [
            'store' => $this->store,
            'type' => $this->type,
            'order' => $this->order,
            'url' => rtrim((string) config('lumee.admin_url'), '/').($this->order ? '/orders/'.$this->order->id : '/settings/billing'),
            'graceDays' => (int) config('plans.grace_days'),
        ]);
    }
}
