<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ShippingRate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductReviewTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private array $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.payments.test_gateway' => true, 'lumee.media_disk' => 'public']);
        Storage::fake('public');
        Mail::fake();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => 'owner@shop-a.test', 'password' => 'secret-pass', 'store_name' => 'Shop A',
        ])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->product = $this->as()->postJson('/api/admin/products', ['title' => 'Silk Scarf', 'price' => 40, 'inventory' => 20, 'status' => 'active'])
            ->assertCreated()->json('data');
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function front(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function review(array $data = [], array $files = []): \Illuminate\Testing\TestResponse
    {
        return $this->front()->post(self::HOST.'/products/silk-scarf/reviews', [
            'author' => 'Jane Doe', 'email' => 'jane@example.com', 'rating' => 5, 'title' => 'Lovely', 'body' => 'Soft and beautiful, love it.', ...$data,
        ] + ($files ? ['photos' => $files] : []));
    }

    private function buy(string $email): void
    {
        $cart = $this->front()->post(self::HOST.'/cart/add', ['variant_id' => $this->product['variants'][0]['id'], 'quantity' => 1])->getCookie('lumee_cart', false)->getValue();
        $this->withUnencryptedCookie('lumee_cart', $cart)->post(self::HOST.'/checkout', [
            'email' => $email, 'first_name' => 'A', 'last_name' => 'B', 'address1' => '1 St', 'city' => 'X', 'zip' => '1', 'country' => 'US',
            'shipping_rate_id' => ShippingRate::withoutGlobalScope('store')->value('id'), 'payment_gateway' => 'test',
        ])->assertRedirect();
    }

    public function test_verified_buyers_are_published_others_wait_for_approval(): void
    {
        $this->front()->get(self::HOST.'/products/silk-scarf')->assertOk()->assertSee('No reviews yet')->assertSee('Write a review');

        // Not a buyer → pending (default: auto-publish verified buyers only)
        $this->review()->assertRedirect('/products/silk-scarf#reviews')->assertSessionHas('review_status', 'Thank you! Your review has been submitted and will appear once approved.');
        $pending = ProductReview::withoutGlobalScope('store')->sole();
        $this->assertSame(['pending', false], [$pending->status, $pending->verified]);
        $this->front()->get(self::HOST.'/products/silk-scarf')->assertDontSee('Soft and beautiful');

        // A buyer → published with a badge, product rating updated
        $this->buy('ana@example.com');
        $photo = UploadedFile::fake()->image('scarf.jpg', 600, 600);
        $this->review(['author' => 'Ana Maria Silva', 'email' => 'ANA@example.com', 'rating' => 4, 'body' => 'Great colour, a bit thin.'], [$photo])
            ->assertSessionHas('review_status', 'Thank you! Your review is now live.');
        $live = ProductReview::withoutGlobalScope('store')->where('email', 'ana@example.com')->sole();
        $this->assertTrue($live->verified);
        $this->assertCount(1, $live->imageUrls());
        Storage::disk('public')->assertExists($live->images[0]['path']);

        $product = Product::withoutGlobalScope('store')->find($this->product['id']);
        $this->assertSame([4.0, 1], [$product->rating_avg, $product->rating_count]);
        $page = $this->front()->get(self::HOST.'/products/silk-scarf')->assertOk()
            ->assertSee('Great colour, a bit thin.')->assertSee('Ana S.')->assertSee('Verified buyer')
            ->assertSee('"aggregateRating"', false)->assertSee('"reviewCount":1', false);
        $page->assertDontSee('ana@example.com');   // emails are never shown

        // Approving the first one updates the average
        $this->as()->patchJson("/api/admin/reviews/{$pending->id}", ['status' => 'published', 'reply' => 'Thank you, Jane!'])->assertOk()
            ->assertJsonPath('data.reply', 'Thank you, Jane!');
        $this->assertSame([4.5, 2], [$product->fresh()->rating_avg, $product->fresh()->rating_count]);
        $this->front()->get(self::HOST.'/products/silk-scarf')->assertSee('Reply from Shop A')->assertSee('Thank you, Jane!');

        // Deleting removes the photo file too
        $this->as()->deleteJson("/api/admin/reviews/{$live->id}")->assertNoContent();
        Storage::disk('public')->assertMissing($live->images[0]['path']);
        $this->assertSame([5.0, 1], [$product->fresh()->rating_avg, $product->fresh()->rating_count]);
    }

    public function test_validation_honeypot_and_settings(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);   // more submissions than the 5/min limit
        $this->review(['rating' => 0, 'body' => 'short'])->assertSessionHasErrorsIn('review', ['rating', 'body']);
        $this->review(['email' => 'nope'])->assertSessionHasErrorsIn('review', ['email']);
        $this->review([], [UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')])->assertSessionHasErrorsIn('review', ['photos.0']);
        $this->review(['website' => 'http://spam'])->assertRedirect();   // honeypot: looks fine, stores nothing
        $this->assertSame(0, ProductReview::withoutGlobalScope('store')->count());

        $this->as()->putJson('/api/admin/reviews/settings', ['enabled' => true, 'auto_publish' => 'all'])->assertOk();
        $this->review()->assertSessionHas('review_status', 'Thank you! Your review is now live.');

        $this->as()->putJson('/api/admin/reviews/settings', ['enabled' => false, 'auto_publish' => 'all'])->assertOk();
        $this->front()->get(self::HOST.'/products/silk-scarf')->assertOk()->assertDontSee('Write a review')->assertDontSee('aggregateRating', false);
        $this->review()->assertNotFound();
        $this->as()->putJson('/api/admin/reviews/settings', ['enabled' => true, 'auto_publish' => 'sometimes'])->assertStatus(422);
    }

    public function test_admin_list_filters_bulk_and_import(): void
    {
        $this->review(['author' => 'One', 'email' => 'one@example.com', 'rating' => 2]);
        $this->review(['author' => 'Two', 'email' => 'two@example.com', 'rating' => 5]);

        $this->as()->getJson('/api/admin/reviews?status=pending')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('counts.pending', 2)
            ->assertJsonPath('settings.auto_publish', 'verified');
        $this->as()->getJson('/api/admin/reviews?rating=2')->assertJsonCount(1, 'data')->assertJsonPath('data.0.author', 'One');
        $this->as()->getJson('/api/admin/reviews?q=two@')->assertJsonCount(1, 'data');

        $ids = ProductReview::withoutGlobalScope('store')->pluck('id')->all();
        $this->as()->postJson('/api/admin/reviews/bulk', ['ids' => $ids, 'action' => 'publish'])->assertOk()->assertJsonPath('affected', 2);
        $this->assertSame(2, Product::withoutGlobalScope('store')->find($this->product['id'])->rating_count);

        $csv = "\xEF\xBB\xBFproduct_handle,rating,author,email,title,body,created_at,images,verified,reply\n"
            ."silk-scarf,5,Mia K,mia@example.com,Perfect,\"Gorgeous, would buy again\",2026-05-01,https://cdn.example.com/a.jpg https://cdn.example.com/b.jpg,yes,Thanks Mia\n"
            ."no-such-product,5,X,,,Body,,,,\n"
            ."silk-scarf,9,Y,,,Body,,,,\n";
        $file = UploadedFile::fake()->createWithContent('reviews.csv', $csv);
        $this->as()->post('/api/admin/reviews/import', ['file' => $file])->assertOk()
            ->assertJsonPath('imported', 1)->assertJsonPath('error_count', 2)->assertJsonPath('errors.0.line', 3);
        $mia = ProductReview::withoutGlobalScope('store')->where('author', 'Mia K')->sole();
        $this->assertSame(['published', true, 'import', '2026-05-01'], [$mia->status, $mia->verified, $mia->source, $mia->created_at->toDateString()]);
        $this->assertSame(['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg'], $mia->imageUrls());
        $this->assertSame(3, Product::withoutGlobalScope('store')->find($this->product['id'])->rating_count);

        $this->as()->post('/api/admin/reviews/import', ['file' => UploadedFile::fake()->createWithContent('r.csv', "handle,stars\nx,1\n")])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_reviews_are_isolated_per_store(): void
    {
        $this->review();
        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@shop-b.test', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $id = ProductReview::withoutGlobalScope('store')->value('id');
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$b['token'], 'X-Store-Id' => (string) $b['stores'][0]['id'], 'Accept' => 'application/json'])
            ->patchJson("/api/admin/reviews/{$id}", ['status' => 'published'])->assertNotFound();
        $this->assertSame(0, Order::withoutGlobalScope('store')->count());
    }
}
