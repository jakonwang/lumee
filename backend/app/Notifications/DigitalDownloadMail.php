<?php

namespace App\Notifications;

use App\Catalog\DigitalDelivery;
use App\Models\Order;
use App\Models\Store;
use App\Themes\ThemeService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Your downloads are ready" — links for the digital products in a paid order. */
class DigitalDownloadMail extends Mailable
{
    public function __construct(public Order $order, public Store $store) {}

    public function envelope(): Envelope
    {
        $settings = NotificationSettings::for($this->store);
        $replyTo = $settings->get('reply_to');

        return new Envelope(
            from: new Address(config('mail.from.address'), $settings->get('sender_name')),
            replyTo: $replyTo ? [new Address($replyTo, $this->store->name)] : [],
            subject: __('Your downloads for order :order', ['order' => $this->order->name()]),
        );
    }

    public function content(): Content
    {
        $theme = app(ThemeService::class)->resolve($this->store);
        $base = $this->store->storefrontUrl();

        return new Content(view: 'emails.digital_downloads', with: [
            'store' => $this->store, 'order' => $this->order, 'c' => OrderMail::palette($theme),
            'links' => DigitalDelivery::links($this->order, $base),
            'orderUrl' => $base.OrderMail::localePrefix($this->order->locale, $this->store).'/orders/'.$this->order->token,
            'max' => DigitalDelivery::MAX_DOWNLOADS, 'days' => DigitalDelivery::DAYS,
        ]);
    }
}
