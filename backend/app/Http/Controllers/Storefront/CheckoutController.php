<?php

namespace App\Http\Controllers\Storefront;

use App\Analytics\Visits;
use App\B2B\B2B;
use App\Console\Commands\ExpireUnpaidOrders;
use App\Customers\CustomerSession;
use App\Http\Controllers\Controller;
use App\Markets\MarketContext;
use App\Markets\MarketSettings;
use App\Models\Cart;
use App\Models\CustomerSubscription;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Payments\AirwallexGateway;
use App\Payments\CashOnDeliveryGateway;
use App\Payments\GatewayRegistry;
use App\Payments\PaymentSettings;
use App\Payments\PayPalGateway;
use App\Payments\RazorpayGateway;
use App\Pricing\PriceLists;
use App\Returns\Returns;
use App\Risk\RiskAnalyzer;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\DiscountService;
use App\Services\StripeTax;
use App\Services\TaxService;
use App\Support\Countries;
use App\Support\Money;
use App\Support\TaxIds;
use App\Tenancy\CurrentStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __construct(
        private CheckoutService $checkout,
        private GatewayRegistry $gateways,
    ) {}

    // Request-scoped services are resolved per call: Laravel may reuse a controller instance across requests.
    private function carts(): CartService
    {
        return app(CartService::class);
    }

    private function current(): CurrentStore
    {
        return app(CurrentStore::class);
    }

    public function show(Request $request): View|RedirectResponse
    {
        $cart = $this->carts()->current();
        if (! $cart || $cart->items->isEmpty()) {
            return redirect('/cart');
        }
        app(Visits::class)->checkout();

        // A code applied earlier may have expired or hit its limit since — drop it and explain why
        $discount = $cart->discount();
        if ($cart->discount_code) {
            try {
                app(DiscountService::class)->assertUsable($discount, $cart->subtotal(), null, $this->current()->getOrFail()->currency);
            } catch (ValidationException $e) {
                $cart->update(['discount_code' => null]);

                return redirect('/cart')->withErrors(['discount_code' => $e->getMessage()]);
            }
        }

        // Default destination: the buyer's saved address, else the market's (first) country
        $country = $request->old('country', CustomerSession::user()?->default_address['country']
            ?? app(MarketContext::class)->market()?->countries[0] ?? 'US');
        $cart->rememberContact(CustomerSession::user()?->email);

        return view($this->current()->themeView('checkout'), [
            'cart' => $cart,
            'discount' => $discount,
            'discounts' => app(DiscountService::class)->discountsFor($cart, $discount),
            'rates' => $this->ratesFor($cart, $country),
            'tax' => $this->taxFor($cart, $country, $request->old('province')),
            'gateways' => $this->gatewaysFor($cart),
            'paymentInstructions' => PaymentSettings::for($this->current()->getOrFail())->instructions(),
            'customer' => CustomerSession::user(),
            'countries' => Countries::all(),
            'country' => $country,
            'giftCard' => $cart->giftCard(),
            'company' => app(B2B::class)->company(),
            'taxExempt' => app(B2B::class)->taxExempt(),
            // Market: import duties on the goods (charged here, included, or paid on delivery) and tax-ID countries
            'duties' => MarketSettings::duties(max(0, $cart->subtotal() - (int) app(DiscountService::class)->discountsFor($cart, $discount)['amount'])),
            'taxIdLabels' => TaxIds::REQUIRED,
            'cod' => CashOnDeliveryGateway::settings(),
        ]);
    }

    /** Bags with a subscription can only use methods that can charge again later (saved card) or manual payment. */
    private function gatewaysFor(?Cart $cart): Collection
    {
        $all = $this->gateways->available();
        if (app(B2B::class)->termsDays() > 0 && ! ($cart && $cart->hasSubscription())) {
            $all = collect(['terms' => $this->gateways->all()->get('terms')])->merge($all);   // pay on account first
        }

        return $cart && $cart->hasSubscription() ? $all->only(['stripe', 'manual', 'test']) : $all;
    }

    /** Rates for another country, when the buyer changes it on the checkout page. */
    public function rates(Request $request): JsonResponse
    {
        $country = (string) $request->query('country');
        abort_unless(array_key_exists($country, Countries::all()), 422);
        $cart = $this->carts()->current();
        $currency = $this->current()->getOrFail()->currency;

        return response()->json([
            'tax' => $cart ? $this->taxFor($cart, $country, $request->query('province'), $request->only('zip', 'city', 'address1')) : null,
            'rates' => $cart ? $this->ratesFor($cart, $country)->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'description' => $r->description,
                'price' => $r->price,
                'display' => $r->price === 0 ? __('Free') : Money::display($r->price, $currency),
            ])->values() : [],
        ]);
    }

    /**
     * Tax for the checkout summary: rate + whether it's included + the taxable item amount, so the
     * page can recompute it in the browser when the shipping method changes (same math as the order).
     */
    private function taxFor(Cart $cart, string $country, ?string $province, array $address = []): array
    {
        $store = $this->current()->getOrFail();
        $tax = app(TaxService::class);
        $settings = $tax->settings($store);
        $rate = app(B2B::class)->taxExempt() ? null : $tax->rateFor($country, $province);
        $subtotal = $cart->subtotal();
        $off = app(DiscountService::class)->discountsFor($cart, $cart->discount())['amount'];
        $taxable = $cart->items->sum(fn ($i) => $i->variant->product->taxable ? $i->lineTotal() : 0);

        // Stripe Tax (US): an effective rate for this address, applied to items + shipping in the browser
        if ($settings['stripe_tax'] && ! $settings['included'] && $country === 'US' && ! app(B2B::class)->taxExempt() && ! empty($address['zip'])) {
            $items = TaxService::taxableAfterDiscount($subtotal, (int) $taxable, $off);
            $shipping = (int) ($this->ratesFor($cart, $country)->first()?->price ?? 0);
            $st = app(StripeTax::class)->calculate($store, $items, $shipping, [...$address, 'province' => $province]);
            if ($st) {
                return ['rate' => $st['rate'], 'name' => 'Sales tax', 'included' => false, 'shipping' => true, 'taxable_items' => $items];
            }
        }

        return [
            'rate' => $rate?->rate ?? 0,
            'name' => $rate?->name,
            'included' => (bool) $settings['included'],
            'shipping' => (bool) $settings['shipping'],
            'taxable_items' => TaxService::taxableAfterDiscount($subtotal, (int) $taxable, $off),
        ];
    }

    /** Rates on the discounted amount and cart weight (matches CheckoutService::placeOrder). */
    private function ratesFor(Cart $cart, string $country): Collection
    {
        $off = app(DiscountService::class)->discountsFor($cart, $cart->discount())['amount'];

        $physical = $cart->items->contains(fn ($i) => ! $i->variant?->product?->digital);

        return $this->checkout->shippingRates($cart->subtotal() - $off, $country, $cart->weight(), $physical);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'address1' => ['required', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'zip' => ['required', 'string', 'max:20'],
            'country' => ['required', Rule::in(array_keys(Countries::all()))],
            'phone' => ['nullable', 'string', 'max:40'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'shipping_rate_id' => ['required', 'integer'],
            'payment_gateway' => ['required', Rule::in($this->gatewaysFor($this->carts()->current())->keys()->all())],
            'note' => ['nullable', 'string', 'max:1000'],
            'accepts_marketing' => ['nullable', 'boolean'],
            'po_number' => ['nullable', 'string', 'max:60'],
        ]);

        // Some countries' customs need the buyer's tax ID (Brazil: CPF / CNPJ)
        if ($label = TaxIds::label($data['country'])) {
            if (! TaxIds::valid($data['country'], (string) ($data['tax_id'] ?? ''))) {
                return back()->withInput()->withErrors(['tax_id' => __('Please enter a valid :label.', ['label' => $label])]);
            }
        } else {
            unset($data['tax_id']);
        }

        $cart = $this->carts()->current();
        if (! $cart || $cart->items->isEmpty()) {
            return redirect('/cart');
        }

        foreach ($cart->items as $item) {
            if ($error = app(PriceLists::class)->quantityError($item->variant_id, (int) $item->quantity)) {
                return redirect('/cart')->withErrors(['cart' => $item->variant->product->title.': '.$error]);
            }
        }
        $min = app(PriceLists::class)->minimum();
        if ($min > 0 && $cart->subtotal() < $min) {
            return redirect('/cart')->withErrors(['cart' => __('Wholesale orders need a subtotal of at least :amount.', ['amount' => Money::display($min, $this->current()->getOrFail()->currency)])]);
        }

        if (app(RiskAnalyzer::class)->blocked($this->current()->id(), $data['email'], $request->ip())) {
            return back()->withInput()->withErrors(['email' => __('We couldn’t process this order. Please contact the store for help.')]);
        }

        $cart->rememberContact($data['email']);
        $order = $this->checkout->placeOrder($this->current()->getOrFail(), $cart, $data);
        app(Visits::class)->order($order);
        if ($cart->recovery_sent_at) {
            $order->forceFill(['recovered' => true])->save();
            $order->log('recovered', __('买家通过弃单挽回邮件完成了购买'));
        }
        $this->carts()->clear();

        // Signed-in shopper without a saved address: remember this one for next time
        $customer = CustomerSession::user();
        if ($customer && ! $customer->default_address && $customer->id === $order->customer_id) {
            $customer->forceFill(['default_address' => $order->shipping_address])->save();
        }

        if ($order->total === 0 && $order->gift_card_total > 0) {
            $order->forceFill(['payment_gateway' => 'gift_card'])->save();
            $order->markPaid();

            return redirect("/orders/{$order->token}");
        }

        try {
            $redirect = $this->gateways->get($order->payment_gateway)
                ->start($order, $this->current()->getOrFail()->storefrontUrl());
        } catch (RuntimeException $e) {
            return redirect("/orders/{$order->token}")->with('error', $e->getMessage());
        }

        return $redirect ? redirect()->away($redirect) : redirect("/orders/{$order->token}");
    }

    /** Checkout page: save the email as soon as it's typed, so an abandoned checkout can be followed up. */
    public function contact(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $cart = $this->carts()->current();
        if ($cart && $cart->items->isNotEmpty()) {
            $cart->rememberContact($data['email']);
        }

        return response()->json(['ok' => true]);
    }

    /** Link from the abandoned checkout email: restore that cart in this browser and go to checkout. */
    public function recover(string $token): RedirectResponse
    {
        $cart = Cart::where('token', $token)->first();
        if (! $cart || $cart->items()->doesntExist()) {
            return redirect('/cart');
        }
        Cookie::queue(CartService::COOKIE, $cart->token, 60 * 24 * 30);

        return redirect('/checkout');
    }

    /** Hands the buyer over to Airwallex's hosted payment page. */
    public function airwallexHandoff(string $token, AirwallexGateway $airwallex): View|RedirectResponse
    {
        $order = Order::where('token', $token)->firstOrFail();
        if ($order->payment_gateway !== 'airwallex' || $order->isPaid()) {
            return redirect("/orders/{$order->token}");
        }
        $handoff = $airwallex->handoff($order, $this->current()->getOrFail()->storefrontUrl());
        if (! $handoff) {
            return redirect("/orders/{$order->token}")->with('error', __('This payment link has expired. Please contact us to complete your order.'));
        }

        return view('storefront.airwallex', ['order' => $order, 'handoff' => $handoff, 'store' => $this->current()->getOrFail()]);
    }

    /** Back from Airwallex: check the intent and confirm the order. */
    public function airwallexReturn(Request $request, AirwallexGateway $airwallex): RedirectResponse
    {
        $token = (string) $request->query('order');
        $order = Str::isUuid($token) ? Order::where('token', $token)->first() : null;
        abort_unless($order, 404);
        try {
            $paid = $airwallex->confirm($order);
        } catch (RuntimeException) {
            $paid = false;
        }

        return $paid
            ? redirect("/orders/{$order->token}?paid=1")
            : redirect("/orders/{$order->token}")->with('error', __('We could not confirm your payment yet. If you were charged, this page will update shortly.'));
    }

    /** Back from Razorpay's payment page: check the signature, then confirm with the API. */
    public function razorpayReturn(Request $request, RazorpayGateway $razorpay): RedirectResponse
    {
        $token = (string) $request->query('order');
        $order = Str::isUuid($token) ? Order::where('token', $token)->first() : null;
        abort_unless($order, 404);
        $paid = false;
        if ($order->payment_gateway === 'razorpay' && RazorpayGateway::validSignature($request->query())
            && $request->query('razorpay_payment_link_id') === $order->payment_reference) {
            try {
                $paid = $razorpay->confirm($order);
            } catch (RuntimeException) {
                $paid = false;
            }
        }

        return $paid
            ? redirect("/orders/{$order->token}?paid=1")
            : redirect("/orders/{$order->token}")->with('error', __('We could not confirm your payment yet. If you were charged, this page will update shortly.'));
    }

    /** PayPal sends the buyer back here after approving; capture right away. */
    public function paypalReturn(Request $request, PayPalGateway $paypal): RedirectResponse
    {
        $token = (string) $request->query('order');
        $order = Str::isUuid($token) ? Order::where('token', $token)->first() : null;
        if (! $order) {
            abort(404);
        }

        try {
            $paid = $order->payment_gateway === 'paypal' && $paypal->capture($order);
        } catch (RuntimeException $e) {
            $paid = false;
        }

        return $paid
            ? redirect("/orders/{$order->token}?paid=1")
            : redirect("/orders/{$order->token}")->with('error', __('We could not confirm your PayPal payment yet. If you were charged, this page will update shortly.'));
    }

    /** The buyer left the payment page (or starting it failed): take them back to pay this order. */
    public function pay(string $token): RedirectResponse
    {
        $order = Order::with('items')->where('token', $token)->firstOrFail();
        if (! self::canPayAgain($order)) {
            return redirect("/orders/{$order->token}");
        }
        try {
            $redirect = $this->gateways->get($order->payment_gateway)->start($order, $this->current()->getOrFail()->storefrontUrl());
        } catch (RuntimeException $e) {
            return redirect("/orders/{$order->token}")->with('error', $e->getMessage());
        }

        return $redirect ? redirect()->away($redirect) : redirect("/orders/{$order->token}");
    }

    /** An online payment that hasn't happened yet and can still be started (not a voucher waiting to be paid in a shop). */
    public static function canPayAgain(Order $order): bool
    {
        return ! $order->isPaid() && ! $order->isCancelled() && $order->total > 0
            && in_array($order->payment_gateway, ExpireUnpaidOrders::ONLINE, true)
            && ! $order->events()->where('type', 'payment_pending')->exists();
    }

    public function order(string $token): View
    {
        $order = Order::with('items')->where('token', $token)->firstOrFail();
        $paymentInstructions = PaymentSettings::for($this->current()->getOrFail())->instructions();
        $returns = app(Returns::class);
        $returnInfo = [
            'blocker' => $returns->blocker($order),
            'returnable' => $returns->returnable($order),
            'requests' => ReturnRequest::where('order_id', $order->id)->latest('id')->get(),
            'reasons' => ReturnRequest::REASONS,
            'window' => Returns::settings($this->current()->getOrFail())['window_days'],
        ];

        $subscriptions = CustomerSubscription::where(fn ($q) => $q->where('origin_order_id', $order->id)->orWhere('id', $order->subscription_id))->get();

        return view($this->current()->themeView('order'), compact('order', 'paymentInstructions', 'returnInfo', 'subscriptions'));
    }
}
