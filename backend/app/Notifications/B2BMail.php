<?php

namespace App\Notifications;

use App\Models\Store;
use App\Themes\ThemeContext;
use App\Themes\ThemeService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * B2B emails. Merchant-facing (Chinese admin wording): application_received, quote_requested, quote_accepted.
 * Buyer-facing (shopper's language): application_approved, application_rejected, quote_sent, payment_overdue.
 */
class B2BMail extends Mailable
{
    public ThemeContext $storeTheme;

    public function __construct(public Store $store, public string $type, public array $data)
    {
        $this->storeTheme = app(ThemeService::class)->resolve($store);
    }

    public function envelope(): Envelope
    {
        $settings = NotificationSettings::for($this->store);
        $replyTo = $settings->get('reply_to');
        $d = $this->data;
        $subject = match ($this->type) {
            'application_received' => __('新的批发申请：:p0', ['p0' => $d['application']->company_name]),
            'application_approved' => __('Your wholesale account at :store is ready', ['store' => $this->store->name]),
            'application_rejected' => __('Your wholesale application at :store', ['store' => $this->store->name]),
            'quote_requested' => __('收到询价单 :p0', ['p0' => $d['quote']->name()]),
            'quote_sent' => __('Your quote :quote from :store', ['quote' => $d['quote']->name(), 'store' => $this->store->name]),
            'quote_accepted' => __('报价单 :p0 已被接受', ['p0' => $d['quote']->name()]),
            'payment_overdue' => __('Payment reminder for order :order', ['order' => $d['order']->name()]),
        };

        return new Envelope(
            from: new Address(config('mail.from.address'), $settings->get('sender_name')),
            replyTo: $replyTo ? [new Address($replyTo, $this->store->name)] : [],
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $admin = rtrim((string) config('lumee.admin_url', ''), '/');
        $quote = $this->data['quote'] ?? null;

        return new Content(view: 'emails.b2b', with: [
            ...$this->data,
            'type' => $this->type, 'store' => $this->store, 'theme' => $this->storeTheme,
            'money' => fn (?int $v) => \App\Support\Money::display($v, $this->store->currency),
            'quoteUrl' => $quote ? $this->store->storefrontUrl()."/quotes/{$quote->token}" : null,
            'adminUrl' => $admin.match ($this->type) {
                'application_received' => '/customers/companies?tab=applications',
                'quote_requested', 'quote_accepted' => '/customers/quotes',
                default => '',
            },
            'c' => OrderMail::palette($this->storeTheme),
        ]);
    }
}
