<?php

namespace App\Notifications;

use App\Models\GiftCard;
use App\Models\Store;
use App\Themes\ThemeContext;
use App\Themes\ThemeService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "You've received a gift card" — code, balance, expiry and the store link. */
class GiftCardMail extends Mailable
{
    public ThemeContext $storeTheme;

    public string $subjectLine;

    public function __construct(public GiftCard $card, public Store $store)
    {
        $this->storeTheme = app(ThemeService::class)->resolve($store);
        $this->subjectLine = __('Your :store gift card', ['store' => $store->name]);
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
        return new Content(view: 'emails.gift_card', with: [
            'card' => $this->card,
            'store' => $this->store,
            'theme' => $this->storeTheme,
            'money' => fn (?int $v) => \App\Support\Money::display($v, $this->card->currency),
            'c' => OrderMail::palette($this->storeTheme),
        ]);
    }
}
