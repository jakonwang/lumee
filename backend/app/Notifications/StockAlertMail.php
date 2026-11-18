<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAlert;
use App\Models\Store;
use App\Themes\ThemeService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "It's back in stock" — one email per sign-up. */
class StockAlertMail extends Mailable
{
    public function __construct(public Store $store, public Product $product, public ProductVariant $variant, public StockAlert $alert) {}

    public function envelope(): Envelope
    {
        $settings = NotificationSettings::for($this->store);
        $replyTo = $settings->get('reply_to');

        return new Envelope(
            from: new Address(config('mail.from.address'), $settings->get('sender_name')),
            replyTo: $replyTo ? [new Address($replyTo, $this->store->name)] : [],
            subject: __(':product is back in stock', ['product' => $this->product->title]),
        );
    }

    public function content(): Content
    {
        $theme = app(ThemeService::class)->resolve($this->store);
        $base = $this->store->storefrontUrl().OrderMail::localePrefix($this->alert->locale, $this->store);
        $image = $this->variant->image?->url() ?? $this->product->featured_image;

        return new Content(view: 'emails.stock_alert', with: [
            'store' => $this->store, 'theme' => $theme, 'product' => $this->product,
            'variantTitle' => $this->variant->title === 'Default' ? null : $this->variant->title,
            'image' => $image && str_starts_with($image, '/') ? $this->store->storefrontUrl().$image : $image,
            'price' => \App\Support\Money::display((int) $this->variant->getRawOriginal('price'), $this->store->currency),
            'url' => $base.'/products/'.$this->product->handle.'?variant='.$this->variant->id,
            'c' => OrderMail::palette($theme),
        ]);
    }
}
