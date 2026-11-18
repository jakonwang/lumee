<?php

namespace App\Http\Controllers\Storefront;

use App\Customers\CustomerSession;
use App\Customers\SendCustomerEmail;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerToken;
use App\Models\Order;
use App\Support\Countries;
use App\Tenancy\CurrentStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Storefront customer accounts: register / log in / log out, email verification,
 * password reset, order history and a default shipping address.
 */
class AccountController extends Controller
{
    private function view(string $template, array $data = []): View
    {
        return view(app(CurrentStore::class)->themeView("account.$template"), $data);
    }

    // ── Sign in ──────────────────────────────────────────────────────────────

    public function showLogin(Request $request): View|RedirectResponse
    {
        $return = self::safeReturn($request->query('return'));

        return CustomerSession::user() ? redirect($return ?? '/account') : $this->view('login', ['return' => $return]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $customer = Customer::where('email', Str::lower($data['email']))->first();   // store-scoped
        if (! $customer || ! $customer->hasAccount() || ! Hash::check($data['password'], $customer->password)) {
            throw ValidationException::withMessages(['email' => __('Incorrect email or password.')]);
        }

        CustomerSession::login($customer);

        return redirect()->intended(self::safeReturn($request->input('return')) ?? '/account');
    }

    /** Only same-site paths (e.g. "/checkout") — never "//evil.com" or "https://…" (open redirect). */
    public static function safeReturn(mixed $path): ?string
    {
        return is_string($path) && preg_match('#^/(?!/)[A-Za-z0-9/_.?=&%-]*$#', $path) ? $path : null;
    }

    public function logout(): RedirectResponse
    {
        CustomerSession::logout();

        return redirect('/');
    }

    // ── Create account ───────────────────────────────────────────────────────

    public function showRegister(): View|RedirectResponse
    {
        return CustomerSession::user() ? redirect('/account') : $this->view('register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'accepts_marketing' => ['nullable', 'boolean'],
        ]);
        $email = Str::lower($data['email']);

        $customer = DB::transaction(function () use ($data, $email) {
            $customer = Customer::where('email', $email)->lockForUpdate()->first();
            if ($customer?->hasAccount()) {
                throw ValidationException::withMessages(['email' => __('An account with this email already exists. Log in, or reset your password.')]);
            }

            // A guest who checked out before keeps their customer record (and orders, once verified)
            $customer ??= new Customer(['email' => $email]);
            $customer->fill([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'accepts_marketing' => (bool) ($data['accepts_marketing'] ?? false),
            ]);
            $customer->password = $data['password'];
            $customer->locale = app(\App\I18n\Localization::class)->locale();
            $customer->email_verified_at = null;   // must prove the address before orders show up
            $customer->save();

            return $customer;
        });

        SendCustomerEmail::dispatch($customer->id, 'verify')->afterCommit();
        CustomerSession::login($customer);

        return redirect('/account')->with('status', __("Welcome! We've sent a link to :email — confirm your email to see your orders.", ['email' => $email]));
    }

    // ── Email verification ──────────────────────────────────────────────────

    public function verify(string $token): RedirectResponse
    {
        $record = CustomerToken::findValid($token, 'verify');
        if (! $record) {
            return redirect(CustomerSession::user() ? '/account' : '/account/login')
                ->with('error', __('This confirmation link is invalid or has expired. Request a new one from your account.'));
        }

        $record->customer->forceFill(['email_verified_at' => now()])->save();
        $record->delete();

        return redirect(CustomerSession::user() ? '/account' : '/account/login')->with('status', __('Thanks — your email is confirmed.'));
    }

    public function resendVerification(): RedirectResponse
    {
        $customer = CustomerSession::user();
        if ($customer && ! $customer->isVerified()) {
            SendCustomerEmail::dispatch($customer->id, 'verify');
        }

        return back()->with('status', __("We've sent a new confirmation link to :email.", ['email' => $customer?->email]));
    }

    // ── Password reset (also how guest buyers claim their account) ──────────

    public function showForgot(): View
    {
        return $this->view('forgot');
    }

    public function forgot(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $customer = Customer::where('email', Str::lower($data['email']))->first();
        if ($customer) {
            SendCustomerEmail::dispatch($customer->id, 'reset');
        }

        // Same answer either way: the form must not reveal who has an account
        return back()->with('status', __('If that email is registered with us, you\'ll receive a link to set a new password shortly.'));
    }

    public function showReset(string $token): View|RedirectResponse
    {
        if (! CustomerToken::findValid($token, 'reset')) {
            return redirect('/account/forgot-password')->with('error', __('This link is invalid or has expired. Please request a new one.'));
        }

        return $this->view('reset', ['token' => $token]);
    }

    public function reset(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(8)]]);
        $record = CustomerToken::findValid($token, 'reset');
        if (! $record) {
            return redirect('/account/forgot-password')->with('error', __('This link is invalid or has expired. Please request a new one.'));
        }

        $customer = $record->customer;
        $customer->password = $data['password'];
        $customer->email_verified_at ??= now();   // they received our email: the address is theirs
        $customer->save();
        CustomerToken::where('customer_id', $customer->id)->delete();

        CustomerSession::login($customer);

        return redirect('/account')->with('status', __('Your password has been updated.'));
    }

    // ── Account pages ────────────────────────────────────────────────────────

    public function index(): View
    {
        $customer = CustomerSession::user();
        $orders = $customer->isVerified()
            ? Order::with('items')->where('customer_id', $customer->id)->latest('id')->paginate(10)
            : null;

        $store = app(CurrentStore::class)->getOrFail();
        $loyalty = \App\Loyalty\Points::settings($store);
        $credits = $loyalty['enabled'] ? \App\Models\GiftCard::where('customer_id', $customer->id)->where('source', 'points')->where('balance', '>', 0)
            ->whereNull('disabled_at')->latest('id')->get() : collect();

        return $this->view('index', ['customer' => $customer, 'orders' => $orders, 'countries' => Countries::all(),
            'loyalty' => $loyalty, 'credits' => $credits,
            'company' => $customer->company_id ? \App\Models\Company::find($customer->company_id) : null,
            'quotes' => \App\Models\Quote::where('customer_id', $customer->id)->latest('id')->limit(10)->get(),
            'subscriptions' => \App\Models\CustomerSubscription::where('customer_id', $customer->id)->where('status', '!=', 'cancelled')->latest('id')->get(), 'pointsValue' => \App\Loyalty\Points::value($store, (int) $customer->points)]);
    }

    /** Put one of my saved credits in the bag. */
    public function applyCredit(int $id, \App\Services\CartService $carts): RedirectResponse
    {
        $card = \App\Models\GiftCard::where('customer_id', CustomerSession::user()->id)->findOrFail($id);
        $cart = $carts->current();
        if (! $cart || $cart->items->isEmpty() || ! $card->usable()) {
            return back();
        }
        $cart->update(['gift_card_code' => $card->code]);

        return redirect('/cart')->with('status', __('Gift card applied. Remaining balance is used at checkout.'));
    }

    /** Points → store credit, applied to the bag straight away. */
    public function redeemPoints(Request $request, \App\Loyalty\Points $points, \App\Services\CartService $carts): RedirectResponse
    {
        $data = $request->validate(['points' => ['required', 'integer', 'min:1', 'max:100000000']]);
        $card = $points->redeem(CustomerSession::user(), (int) $data['points']);
        $cart = $carts->current();
        if ($cart && $cart->items->isNotEmpty()) {
            $cart->update(['gift_card_code' => $card->code]);

            return redirect('/cart')->with('status', __('Points redeemed: :amount credit applied to your bag.', ['amount' => \App\Support\Money::display($card->balance, $card->currency)]));
        }

        return back()->with('status', __('Points redeemed: :amount credit saved to your account.', ['amount' => \App\Support\Money::display($card->balance, $card->currency)]));
    }

    /** GDPR access: everything the store holds about this customer, as a JSON file. */
    public function downloadData(): \Symfony\Component\HttpFoundation\Response
    {
        $customer = CustomerSession::user();
        $store = app(\App\Tenancy\CurrentStore::class)->getOrFail();
        $data = app(\App\Customers\Privacy::class)->export($store, $customer->email);
        \App\Models\PrivacyRequest::create(['store_id' => $store->id, 'customer_id' => $customer->id, 'email' => $customer->email,
            'type' => 'export', 'source' => 'customer', 'status' => 'completed', 'completed_at' => now()]);

        return response()->streamDownload(fn () => print(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'my-data-'.now()->format('Ymd').'.json', ['Content-Type' => 'application/json']);
    }

    /** GDPR erasure: the store reviews it (open orders must be finished first). Needs the password. */
    public function requestDeletion(Request $request): RedirectResponse
    {
        $customer = CustomerSession::user();
        $request->validate(['current_password' => ['required', 'string']]);
        if (! $customer->password || ! \Illuminate\Support\Facades\Hash::check($request->input('current_password'), $customer->password)) {
            return back()->withErrors(['delete_password' => __('That password isn’t right.')]);
        }
        \App\Models\PrivacyRequest::firstOrCreate(
            ['store_id' => $customer->store_id, 'email' => $customer->email, 'type' => 'erase', 'status' => 'pending'],
            ['customer_id' => $customer->id, 'source' => 'customer'],
        );

        return back()->with('status', __('We’ve received your request to delete your account. The store will process it within 30 days and email you when it’s done.'));
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $customer = CustomerSession::user();
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'accepts_marketing' => ['nullable', 'boolean'],
            'birthday' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
        ]);
        $customer->forceFill(['birthday' => $data['birthday'] ?? null]);
        unset($data['birthday']);
        $customer->fill([...$data, 'accepts_marketing' => (bool) ($data['accepts_marketing'] ?? false)])->save();

        return back()->with('status', __('Your details have been saved.'));
    }

    public function updateAddress(Request $request): RedirectResponse
    {
        $customer = CustomerSession::user();
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'address1' => ['required', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'zip' => ['required', 'string', 'max:20'],
            'country' => ['required', Rule::in(array_keys(Countries::all()))],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);
        $customer->forceFill(['default_address' => $data])->save();

        return redirect('/account#address')->with('status', __('Your address has been saved.'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $customer = CustomerSession::user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        if (! Hash::check($data['current_password'], $customer->password)) {
            throw ValidationException::withMessages(['current_password' => __('Your current password is incorrect.')]);
        }
        $customer->password = $data['password'];
        $customer->save();
        Auth::guard('customer')->login($customer);   // keep this browser signed in with the new password
        $request->session()->regenerate();

        return back()->with('status', __('Your password has been changed.'));
    }
}
