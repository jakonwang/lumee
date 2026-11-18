<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Marketing\Newsletter;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Tenancy\CurrentStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Newsletter signup (popup, home page section), unsubscribe, and campaign open / click tracking. */
class NewsletterController extends Controller
{
    public function subscribe(Request $request, CurrentStore $current, Newsletter $newsletter): JsonResponse|RedirectResponse
    {
        // Bots fill every field; people never see this one
        if (filled($request->input('website'))) {
            return $request->wantsJson() ? response()->json(['ok' => true]) : back()->with('newsletter_status', __('Thanks for subscribing!'));
        }
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'source' => ['nullable', 'in:popup,form'],
        ], ['email.required' => __('Please enter your email.'), 'email.email' => __('Please enter a valid email address.')]);

        $newsletter->subscribe($data['email'], $data['source'] ?? 'form');
        $code = ($data['source'] ?? null) === 'popup' ? $newsletter->popupCode($current->getOrFail()) : null;
        $message = $code ? __('Thanks for subscribing! Use code :code at checkout.', ['code' => $code]) : __('Thanks for subscribing!');

        return $request->wantsJson()
            ? response()->json(['ok' => true, 'code' => $code, 'message' => $message])
            : back()->with('newsletter_status', $message);
    }

    /** Confirmation page — a plain GET never unsubscribes (link scanners open every link). */
    public function unsubscribePage(string $token, CurrentStore $current): View
    {
        $recipient = EmailCampaignRecipient::with('customer')->where('token', $token)->firstOrFail();

        return view('storefront.unsubscribe', [
            'store' => $current->getOrFail(),
            'email' => $recipient->email,
            'done' => ! $recipient->customer?->accepts_marketing,
            'token' => $token,
        ]);
    }

    /** Form submit, and RFC 8058 one-click unsubscribe from the mail client (List-Unsubscribe-Post). */
    public function unsubscribe(string $token, CurrentStore $current): View|Response
    {
        $recipient = EmailCampaignRecipient::with('customer')->where('token', $token)->firstOrFail();
        $customer = $recipient->customer;
        if ($customer?->accepts_marketing) {
            $customer->setMarketingConsent(false, 'unsubscribe');
            $customer->save();
            EmailCampaign::whereKey($recipient->email_campaign_id)->increment('unsubscribed_count');
        }
        if (request()->has('List-Unsubscribe')) {
            return response()->noContent();
        }

        return view('storefront.unsubscribe', ['store' => $current->getOrFail(), 'email' => $recipient->email, 'done' => true, 'token' => $token]);
    }

    /** 1×1 transparent GIF that records the first open. */
    public function open(string $token): Response
    {
        $recipient = EmailCampaignRecipient::where('token', $token)->first();
        if ($recipient && ! $recipient->opened_at) {
            $recipient->forceFill(['opened_at' => now()])->save();
            EmailCampaign::whereKey($recipient->email_campaign_id)->increment('opened_count');
        }

        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, [
            'Content-Type' => 'image/gif', 'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    /** Tracked link: records the first click, then redirects. The URL is signed, so this is never an open redirect. */
    public function click(Request $request, string $token): RedirectResponse
    {
        $url = (string) $request->query('u');
        abort_unless(\App\Marketing\CampaignSender::linkSignature($token, $url) === $request->query('s') && preg_match('#^https?://#i', $url), 404);

        $recipient = EmailCampaignRecipient::where('token', $token)->first();
        if ($recipient && ! $recipient->clicked_at) {
            $wasOpened = $recipient->opened_at !== null;
            $recipient->forceFill(['clicked_at' => now(), 'opened_at' => $recipient->opened_at ?? now()])->save();
            EmailCampaign::whereKey($recipient->email_campaign_id)->increment('clicked_count');
            if (! $wasOpened) {
                EmailCampaign::whereKey($recipient->email_campaign_id)->increment('opened_count');   // images off but clicked = opened
            }
        }

        // Links back into this store are tagged so the visit shows up as email traffic in analytics
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === strtolower($request->getHost()) && ! str_contains($url, 'utm_')) {
            $campaign = $recipient ? EmailCampaign::whereKey($recipient->email_campaign_id)->value('id') : null;
            [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);
            $url = $base.(str_contains($base, '?') ? '&' : '?').http_build_query(array_filter([
                'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => $campaign ? 'campaign-'.$campaign : null,
            ])).($fragment !== null ? '#'.$fragment : '');
        }

        return redirect()->away($url);
    }
}
