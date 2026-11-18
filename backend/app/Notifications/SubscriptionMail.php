<?php

namespace App\Notifications;

use App\Models\CustomerSubscription;
use App\Models\Store;
use App\Subscriptions\Plans;
use App\Themes\ThemeContext;
use App\Themes\ThemeService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Subscription started / renewal failed / cancelled — each with the buyer's manage link. */
class SubscriptionMail extends Mailable
{
    public ThemeContext $storeTheme;

    public function __construct(public CustomerSubscription $subscription, public Store $store, public string $type)
    {
        $this->storeTheme = app(ThemeService::class)->resolve($store);
    }

    public function envelope(): Envelope
    {
        $settings = NotificationSettings::for($this->store);
        $replyTo = $settings->get('reply_to');
        $subject = match ($this->type) {
            'started' => __('Your subscription at :store has started', ['store' => $this->store->name]),
            'renewal_failed' => __('We couldn’t renew your subscription at :store', ['store' => $this->store->name]),
            default => __('Your subscription at :store has been cancelled', ['store' => $this->store->name]),
        };

        return new Envelope(
            from: new Address(config('mail.from.address'), $settings->get('sender_name')),
            replyTo: $replyTo ? [new Address($replyTo, $this->store->name)] : [],
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $s = $this->subscription;

        return new Content(view: 'emails.subscription', with: [
            's' => $s, 'store' => $this->store, 'type' => $this->type, 'theme' => $this->storeTheme,
            'interval' => Plans::label($s->interval),
            'manageUrl' => $this->store->storefrontUrl().OrderMail::localePrefix($s->locale, $this->store)."/subscriptions/{$s->token}",
            'c' => OrderMail::palette($this->storeTheme),
        ]);
    }
}
