<?php

namespace App\Notifications;

use App\Models\Cart;
use App\Models\Store;
use App\Themes\ThemeContext;
use App\Themes\ThemeService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "You left something in your bag" — sent once per abandoned checkout, in the shopper's language. */
class RecoveryMail extends Mailable
{
    public ThemeContext $storeTheme;

    /** Fixed when built (inside the shopper's language), not when the mailer reads it later. */
    public string $subjectLine;

    public function __construct(public Cart $cart, public Store $store, public ?string $discountCode = null)
    {
        $this->storeTheme = app(ThemeService::class)->resolve($store);
        $this->subjectLine = __('You left something in your bag');
        $this->locale(app()->getLocale());
    }

    public function envelope(): Envelope
    {
        $settings = NotificationSettings::for($this->store);
        $replyTo = $settings->get('reply_to');

        return new Envelope(
            from: new Address(config('mail.from.address'), $settings->get('sender_name')),
            replyTo: $replyTo ? [new Address($replyTo, $this->store->name)] : [],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        $base = $this->store->storefrontUrl().OrderMail::localePrefix($this->cart->locale, $this->store);

        return new Content(view: 'emails.recovery', with: [
            'cart' => $this->cart,
            'store' => $this->store,
            'theme' => $this->storeTheme,
            'discountCode' => $this->discountCode,
            'recoverUrl' => "{$base}/cart/recover/{$this->cart->token}",
            'money' => fn (?int $v) => \App\Support\Money::display($v, $this->store->currency),
            'c' => OrderMail::palette($this->storeTheme),
        ]);
    }
}
