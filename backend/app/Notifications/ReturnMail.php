<?php

namespace App\Notifications;

use App\Models\ReturnRequest;
use App\Models\Store;
use App\Themes\ThemeContext;
use App\Themes\ThemeService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Return request emails: "new request" to the merchant, "approved / declined" to the buyer. */
class ReturnMail extends Mailable
{
    public ThemeContext $storeTheme;

    public function __construct(public ReturnRequest $return, public Store $store, public string $audience)
    {
        $this->storeTheme = app(ThemeService::class)->resolve($store);
    }

    public function envelope(): Envelope
    {
        $settings = NotificationSettings::for($this->store);
        $replyTo = $settings->get('reply_to');
        $order = $this->return->order;
        $subject = $this->audience === 'merchant'
            ? __('订单 :p0 收到退货申请', ['p0' => $order->name()])
            : ($this->return->status === 'approved'
                ? __('Your return for order :order is approved', ['order' => $order->name()])
                : __('Update on your return for order :order', ['order' => $order->name()]));

        return new Envelope(
            from: new Address(config('mail.from.address'), $settings->get('sender_name')),
            replyTo: $replyTo ? [new Address($replyTo, $this->store->name)] : [],
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $order = $this->return->order;
        $items = collect($this->return->items)->map(function ($i) use ($order) {
            $item = $order->items->firstWhere('id', $i['order_item_id']);

            return ['title' => $item?->title, 'variant' => $item?->variant_title, 'quantity' => $i['quantity']];
        });

        return new Content(view: 'emails.return', with: [
            'r' => $this->return,
            'order' => $order,
            'items' => $items,
            'store' => $this->store,
            'theme' => $this->storeTheme,
            'to' => $this->audience,
            'reason' => __(($this->audience === 'merchant' ? ReturnRequest::ADMIN_REASONS : ReturnRequest::REASONS)[$this->return->reason] ?? $this->return->reason),
            'orderUrl' => $this->store->storefrontUrl().OrderMail::localePrefix($order->locale, $this->store)."/orders/{$order->token}",
            'adminUrl' => rtrim((string) config('lumee.admin_url', ''), '/')."/orders/{$order->id}",
            'c' => OrderMail::palette($this->storeTheme),
        ]);
    }
}
