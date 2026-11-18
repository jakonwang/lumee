<?php

namespace App\Notifications;

use App\Marketing\CampaignSender;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\Product;
use App\Models\Store;
use App\Themes\ThemeService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** One marketing email to one recipient: store-branded, tracked links, one-click unsubscribe. */
class CampaignMail extends Mailable
{
    public function __construct(
        public EmailCampaign $campaign,
        public Store $store,
        public EmailCampaignRecipient $recipient,
        public bool $test = false,
    ) {}

    public function envelope(): Envelope
    {
        $settings = NotificationSettings::for($this->store);
        $replyTo = $settings->get('reply_to');

        return new Envelope(
            from: new Address(config('mail.from.address'), $settings->get('sender_name')),
            replyTo: $replyTo ? [new Address($replyTo, $this->store->name)] : [],
            subject: ($this->test ? '[TEST] ' : '').$this->campaign->subject,
        );
    }

    /** RFC 8058: Gmail / Yahoo show an "Unsubscribe" button and POST here. */
    public function headers(): Headers
    {
        return new Headers(text: $this->test ? [] : [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        $theme = app(ThemeService::class)->resolve($this->store);
        $base = $this->store->storefrontUrl();
        $prefix = OrderMail::localePrefix($this->recipient->customer?->locale, $this->store);
        $abs = fn (?string $u) => $u && str_starts_with($u, '/') ? $base.$u : $u;

        $ids = $this->campaign->product_ids ?? [];
        $viewed = $this->recipient->customer?->last_viewed_product_id;
        if ($viewed && $this->campaign->email_flow_id && \App\Models\EmailFlow::withoutGlobalScope('store')->whereKey($this->campaign->email_flow_id)->value('type') === 'browse_abandon') {
            $ids = [(int) $viewed, ...array_diff($ids, [(int) $viewed])];
        }
        $ids = array_slice($ids, 0, 4);
        $products = Product::withoutGlobalScope('store')->where('store_id', $this->store->id)->active()->whereIn('id', $ids)->with('variants')->get()
            ->sortBy(fn ($p) => array_search($p->id, $ids, true))->values()
            ->map(fn (Product $p) => [
                'title' => $p->title,
                'image' => $abs($p->featured_image),
                'price' => $p->variants->min('price'),
                'compare' => $p->variants->sortBy('price')->first()?->compare_at_price,
                'url' => $this->track($base.$prefix.'/products/'.$p->handle),
            ]);

        // Links in the merchant's Markdown are tracked too
        $body = preg_replace_callback('/href="(https?:\/\/[^"]+)"/i', fn ($m) => 'href="'.e($this->track(html_entity_decode($m[1]))).'"', $this->campaign->html());
        $button = $this->campaign->button_url ? $this->track($abs($this->campaign->button_url)) : null;

        return new Content(view: 'emails.campaign', with: [
            'campaign' => $this->campaign,
            'store' => $this->store,
            'theme' => $theme,
            'body' => $body,
            'products' => $products,
            'buttonUrl' => $button,
            'shopUrl' => $this->track($base.$prefix.'/'),
            'unsubscribeUrl' => $this->test ? '#' : $this->unsubscribeUrl(),
            'openPixel' => $this->test ? null : $base.'/email/open/'.$this->recipient->token.'.gif',
            'money' => fn (?int $v) => \App\Support\Money::display($v, $this->store->currency),
            'c' => OrderMail::palette($theme),
        ]);
    }

    private function track(string $url): string
    {
        if ($this->test) {
            return $url;
        }
        $token = $this->recipient->token;

        return $this->store->storefrontUrl().'/email/click/'.$token.'?'.http_build_query(['u' => $url, 's' => CampaignSender::linkSignature($token, $url)]);
    }

    private function unsubscribeUrl(): string
    {
        return $this->store->storefrontUrl().'/email/unsubscribe/'.$this->recipient->token;
    }
}
