<?php

namespace Tests\Feature;

use App\Customers\CustomerAccountMail;
use App\Models\Customer;
use App\Models\CustomerToken;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CustomerAccountTest extends TestCase
{
    use RefreshDatabase;

    private const A = 'http://shop-a.lumee.localhost';

    private const B = 'http://shop-b.lumee.localhost';

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true]);
        Mail::fake();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->a = $this->merchant('a@merchant.test', 'Shop A');
        $this->merchant('b@merchant.test', 'Shop B');
    }

    private function merchant(string $email, string $store): array
    {
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => $email, 'password' => 'secret-pass', 'store_name' => $store])->assertCreated();

        return ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    /** Guest checkout at shop A as $email (creates the customer record + a paid order). */
    private function guestOrder(string $email): void
    {
        $this->app['auth']->forgetGuards();
        $id = $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id']])
            ->postJson('/api/admin/products', ['title' => 'Ring', 'price' => 40, 'inventory' => 10, 'status' => 'active'])->json('data.id');
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $variant = ProductVariant::withoutGlobalScope('store')->where('product_id', $id)->value('id');
        $cart = $this->post(self::A.'/cart/add', ['variant_id' => $variant, 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $rate = ShippingRate::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->first();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::A.'/checkout', [
            'email' => $email, 'first_name' => 'Ada', 'last_name' => 'L', 'address1' => '1 St', 'city' => 'X', 'zip' => '1',
            'country' => 'US', 'shipping_rate_id' => $rate->id, 'payment_gateway' => 'test',
        ])->assertRedirect();
        $this->app['auth']->forgetGuards();
    }

    private function register(string $host, string $email, string $password = 'correct-horse'): \Illuminate\Testing\TestResponse
    {
        return $this->post($host.'/account/register', [
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => $email,
            'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    /** The link from the last account email of this type. */
    private function linkFromMail(string $type): string
    {
        $mail = Mail::sent(CustomerAccountMail::class, fn ($m) => $m->type === $type)->last();
        $this->assertNotNull($mail, "no {$type} email sent");

        return parse_url($mail->url, PHP_URL_PATH);
    }

    private function logout(): void
    {
        $this->post(self::A.'/account/logout');
        $this->app['auth']->forgetGuards();
    }

    public function test_registering_someone_elses_email_never_shows_their_orders_until_verified(): void
    {
        $this->guestOrder('ada@example.com');

        $this->register(self::A, 'Ada@Example.com')->assertRedirect('/account');
        $this->assertSame(1, Customer::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->count());   // reused, not duplicated

        $page = $this->get(self::A.'/account')->assertOk();
        $page->assertSee('Please confirm your email address')->assertDontSee('#1001');

        // Following the emailed link proves ownership → orders appear
        $this->get(self::A.$this->linkFromMail('verify'))->assertRedirect('/account');
        $this->get(self::A.'/account')->assertSee('#1001')->assertDontSee('Please confirm your email address');

        // Links are one-time
        $this->get(self::A.$this->linkFromMail('verify'))->assertSessionHas('error');
    }

    public function test_login_logout_duplicate_and_wrong_password(): void
    {
        $this->register(self::A, 'ada@example.com');
        $this->logout();
        $this->get(self::A.'/account')->assertRedirect('/account/login');

        $this->register(self::A, 'ada@example.com')->assertSessionHasErrors('email');
        $this->post(self::A.'/account/login', ['email' => 'ada@example.com', 'password' => 'wrong-pass'])
            ->assertSessionHasErrors(['email' => 'Incorrect email or password.']);
        $this->post(self::A.'/account/login', ['email' => 'ada@example.com', 'password' => 'correct-horse'])->assertRedirect('/account');
        $this->get(self::A.'/account')->assertOk()->assertSee('Hello, Ada');
        $this->assertNotNull(Customer::withoutGlobalScope('store')->value('last_login_at'));

        // Return path after login: same-site only
        $this->logout();
        $this->post(self::A.'/account/login', ['email' => 'ada@example.com', 'password' => 'correct-horse', 'return' => '/checkout'])->assertRedirect('/checkout');
        $this->logout();
        $this->post(self::A.'/account/login', ['email' => 'ada@example.com', 'password' => 'correct-horse', 'return' => '//evil.example'])->assertRedirect('/account');
    }

    public function test_accounts_are_per_store(): void
    {
        $this->register(self::A, 'ada@example.com');
        $this->logout();

        // Same credentials don't work at another store; the account is shop A's
        $this->post(self::B.'/account/login', ['email' => 'ada@example.com', 'password' => 'correct-horse'])->assertSessionHasErrors('email');
        $this->register(self::B, 'ada@example.com')->assertRedirect('/account');
        $this->assertSame(2, Customer::withoutGlobalScope('store')->where('email', 'ada@example.com')->count());

        // A token issued by shop A can't be used on shop B
        $this->get(self::A.'/account/login');
        $customerA = Customer::withoutGlobalScope('store')->where('store_id', $this->a['store_id'])->first();
        $this->app[\App\Tenancy\CurrentStore::class]->set($customerA->store);
        $token = CustomerToken::issue($customerA, 'reset', 60);
        $this->get(self::B.'/account/reset/'.$token)->assertRedirect('/account/forgot-password');
        $this->get(self::A.'/account/reset/'.$token)->assertOk();
    }

    public function test_guest_buyer_sets_a_password_through_the_reset_link(): void
    {
        $this->guestOrder('guest@example.com');

        // Unknown email: same answer, nothing sent
        $this->post(self::A.'/account/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status');
        Mail::assertNotSent(CustomerAccountMail::class);

        $this->post(self::A.'/account/forgot-password', ['email' => 'guest@example.com'])->assertSessionHas('status');
        $mail = Mail::sent(CustomerAccountMail::class)->last();
        $this->assertStringContainsString('Set up your account', $mail->render());
        $link = $this->linkFromMail('reset');

        $this->get(self::A.$link)->assertOk()->assertSee('New password');
        $this->post(self::A.$link, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post(self::A.$link, ['password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])->assertRedirect('/account');

        // Signed in, email counts as verified (they got our email) → orders visible
        $this->get(self::A.'/account')->assertSee('#1001');
        $this->get(self::A.$link)->assertRedirect('/account/forgot-password');      // used up
        $this->assertSame(0, CustomerToken::withoutGlobalScope('store')->count());

        // Expired links don't work
        $c = Customer::withoutGlobalScope('store')->first();
        $this->app[\App\Tenancy\CurrentStore::class]->set($c->store);
        $old = CustomerToken::issue($c, 'reset', 60);
        CustomerToken::withoutGlobalScope('store')->update(['expires_at' => now()->subMinute()]);
        $this->get(self::A.'/account/reset/'.$old)->assertRedirect('/account/forgot-password');
    }

    public function test_profile_address_password_and_checkout_prefill(): void
    {
        $this->register(self::A, 'ada@example.com');

        $this->post(self::A.'/account/address', [
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'address1' => '12 Analytical Way', 'city' => 'London',
            'zip' => 'NW1', 'country' => 'GB',
        ])->assertRedirect('/account#address');
        $this->post(self::A.'/account/address', ['first_name' => 'Ada'])->assertSessionHasErrors(['last_name', 'address1', 'country']);
        $this->post(self::A.'/account/profile', ['first_name' => 'Augusta', 'last_name' => 'King', 'accepts_marketing' => '1'])->assertSessionHas('status');
        $c = Customer::withoutGlobalScope('store')->first();
        $this->assertSame(['Augusta', true, 'GB'], [$c->first_name, $c->accepts_marketing, $c->default_address['country']]);

        $this->post(self::A.'/account/password', ['current_password' => 'nope', 'password' => 'another-pass', 'password_confirmation' => 'another-pass'])
            ->assertSessionHasErrors('current_password');
        $this->post(self::A.'/account/password', ['current_password' => 'correct-horse', 'password' => 'another-pass', 'password_confirmation' => 'another-pass'])
            ->assertSessionHas('status');

        // Checkout: email + saved address filled in, country preselected
        $cart = $this->guestOrderCartOnly();
        $this->withUnencryptedCookie('lumee_cart', $cart)->get(self::A.'/checkout')->assertOk()
            ->assertSee('Signed in as ada@example.com')
            ->assertSee('value="12 Analytical Way"', false)
            ->assertSee('<option value="GB" selected', false);

        // Header shows the account link
        $this->get(self::A.'/')->assertSee('href="/account"', false);
    }

    private function guestOrderCartOnly(): string
    {
        $this->app['auth']->forgetGuards();
        $id = $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id']])
            ->postJson('/api/admin/products', ['title' => 'Ring', 'price' => 40, 'inventory' => 10, 'status' => 'active'])->json('data.id');
        $this->flushHeaders();
        $variant = ProductVariant::withoutGlobalScope('store')->where('product_id', $id)->value('id');
        return $this->post(self::A.'/cart/add', ['variant_id' => $variant, 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
    }

    public function test_admin_customer_list_shows_account_state(): void
    {
        $this->guestOrder('guest@example.com');
        $this->register(self::A, 'member@example.com');
        $this->app['auth']->forgetGuards();

        $rows = collect($this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id']])
            ->getJson('/api/admin/customers?q=example')->assertOk()->json('data'))->pluck('account', 'email');
        $this->assertSame(['member@example.com' => 'unverified', 'guest@example.com' => 'none'], $rows->all());
    }
}
