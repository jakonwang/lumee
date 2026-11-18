<?php

namespace App\Customers;

use App\Models\Customer;
use App\Models\Store;
use App\Notifications\NotificationSettings;
use App\Notifications\OrderMail;
use App\Themes\ThemeContext;
use App\Themes\ThemeService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Store-branded account emails: verify email address, reset password. */
class CustomerAccountMail extends Mailable
{
    public ThemeContext $storeTheme;

    public function __construct(public Customer $customer, public string $type, public string $url, public Store $store)
    {
        $this->storeTheme = app(ThemeService::class)->resolve($store);
    }

    public function envelope(): Envelope
    {
        $settings = NotificationSettings::for($this->store);
        $replyTo = $settings->get('reply_to');

        return new Envelope(
            from: new Address(config('mail.from.address'), $settings->get('sender_name')),
            replyTo: $replyTo ? [new Address($replyTo, $this->store->name)] : [],
            subject: $this->type === 'verify'
                ? __('Confirm your email for :store', ['store' => $this->store->name])
                : __('Reset your :store password', ['store' => $this->store->name]),
        );
    }

    public function content(): Content
    {
        return new Content(view: "emails.customer.{$this->type}", with: [
            'customer' => $this->customer,
            'store' => $this->store,
            'theme' => $this->storeTheme,
            'url' => $this->url,
            'c' => OrderMail::palette($this->storeTheme),
        ]);
    }
}
