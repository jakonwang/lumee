<?php

namespace App\Http\Controllers\Storefront;

use App\Customers\CustomerSession;
use App\Http\Controllers\Controller;
use App\I18n\Localization;
use App\Models\Customer;
use App\Tenancy\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Continue with Google" for shoppers. Stores live on many hosts (sub-domains, custom domains), so Google sends
 * everyone back to one platform address (/auth/google/callback). That address gets the verified email from Google
 * and hands the shop a one-time ticket; the shop checks the ticket belongs to this browser (a nonce kept in its
 * session) and signs the shopper in.
 */
class GoogleLoginController extends Controller
{
    private const TICKET_SECONDS = 120;

    public static function enabled(): bool
    {
        return (bool) config('services.google_login.client_id') && (bool) config('services.google_login.client_secret');
    }

    /** Shop: off to Google. */
    public function start(Request $request, CurrentStore $current): RedirectResponse
    {
        abort_unless(self::enabled(), 404);
        $nonce = Str::random(40);
        $request->session()->put('google_login_nonce', $nonce);
        $state = Crypt::encryptString(json_encode([
            'store' => $current->id(),
            'complete' => $request->getSchemeAndHttpHost().app(Localization::class)->url('/account/google/complete'),
            'return' => AccountController::safeReturn($request->query('return')),
            'nonce' => $nonce,
        ]));

        return redirect('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google_login.client_id'),
            'redirect_uri' => self::callbackUrl(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]));
    }

    /** Platform: Google answered. Exchange the code, keep what we learned under a one-time ticket, back to the shop. */
    public function callback(Request $request): RedirectResponse
    {
        abort_unless(self::enabled(), 404);
        try {
            $state = json_decode(Crypt::decryptString((string) $request->query('state')), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(400);
        }
        $back = (string) ($state['complete'] ?? '');
        abort_unless(str_starts_with($back, 'http'), 400);
        if ($request->query('error') || ! $request->query('code')) {
            return redirect($back.'?'.http_build_query(['error' => 'cancelled']));   // the shopper closed Google's page
        }

        $token = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $request->query('code'), 'client_id' => config('services.google_login.client_id'),
            'client_secret' => config('services.google_login.client_secret'), 'redirect_uri' => self::callbackUrl(), 'grant_type' => 'authorization_code',
        ]);
        $profile = $token->successful()
            ? Http::withToken((string) $token->json('access_token'))->get('https://openidconnect.googleapis.com/v1/userinfo')
            : null;
        if (! $profile?->successful() || ! $profile->json('email') || ! $profile->json('email_verified') || ! $profile->json('sub')) {
            report(new \RuntimeException('Google sign-in failed: '.($profile?->body() ?? $token->body())));

            return redirect($back.'?'.http_build_query(['error' => 'failed']));
        }

        $ticket = Str::random(48);
        Cache::put("google-login:{$ticket}", [
            'store' => $state['store'], 'nonce' => $state['nonce'], 'return' => $state['return'],
            'sub' => (string) $profile->json('sub'), 'email' => Str::lower((string) $profile->json('email')),
            'first_name' => (string) $profile->json('given_name'), 'last_name' => (string) $profile->json('family_name'),
        ], self::TICKET_SECONDS);

        return redirect($back.'?'.http_build_query(['ticket' => $ticket]));
    }

    /** Shop: redeem the ticket (once, in the browser that started) and sign in — creating the account if needed. */
    public function complete(Request $request, CurrentStore $current): RedirectResponse
    {
        abort_unless(self::enabled(), 404);
        $fail = fn () => redirect('/account/login')->withErrors(['email' => __('Google sign-in didn’t work. Please try again or use your email.')]);
        if ($request->query('error') === 'cancelled') {
            return redirect('/account/login');
        }
        $data = Cache::pull('google-login:'.(string) $request->query('ticket'));
        $nonce = $request->session()->pull('google_login_nonce');
        if (! is_array($data) || (int) $data['store'] !== $current->id() || ! $nonce || ! hash_equals((string) $data['nonce'], (string) $nonce)) {
            return $fail();
        }

        $customer = DB::transaction(function () use ($data) {
            $customer = Customer::where('google_id', $data['sub'])->first()
                ?? Customer::where('email', $data['email'])->lockForUpdate()->first()   // a guest or password account with the same (Google-verified) email
                ?? new Customer(['email' => $data['email'], 'first_name' => $data['first_name'] ?: null, 'last_name' => $data['last_name'] ?: null]);
            $customer->google_id = $data['sub'];
            $customer->email_verified_at ??= now();   // Google verified the address
            if (! $customer->exists) {
                $customer->locale = app(Localization::class)->locale();
            }
            $customer->save();

            return $customer;
        });

        CustomerSession::login($customer);

        return redirect($data['return'] ?? '/account');
    }

    public static function callbackUrl(): string
    {
        return rtrim((string) (config('services.google_login.redirect_host') ?: config('app.url')), '/').'/auth/google/callback';
    }
}
