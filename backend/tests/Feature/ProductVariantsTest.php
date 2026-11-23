<?php

namespace Tests\Feature;

use App\Models\ProductImage;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductVariantsTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->a = $this->register('a@example.com', 'Shop A');
    }

    private function register(string $email, string $store): array
    {
        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => $email, 'password' => 'secret-pass', 'store_name' => $store,
        ])->assertCreated();

        return ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(array $m): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$m['token'], 'X-Store-Id' => (string) $m['store_id']]);
    }

    private function upload(array $m, string $name = 'photo.jpg'): array
    {
        return $this->as($m)->post('/api/admin/uploads/images', [
            'file' => UploadedFile::fake()->image($name, 800, 1000),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
    }

    private function necklacePayload(array $images): array
    {
        $v = fn ($mat, $len, $price, $stock, $img = null) => [
            'options' => ['Material' => $mat, 'Length' => $len], 'price' => $price, 'inventory' => $stock, 'image_id' => $img,
        ];

        return [
            'title' => 'Butterfly Necklace',
            'status' => 'active',
            'images' => array_map(fn ($i) => ['id' => $i['id']], $images),
            'options' => [
                ['name' => 'Material', 'values' => ['Gold', 'Silver']],
                ['name' => 'Length', 'values' => ['16in', '18in']],
            ],
            'variants' => [
                $v('Gold', '16in', 129, 5, $images[0]['id']),
                $v('Gold', '18in', 139, 0, $images[0]['id']),
                $v('Silver', '16in', 99, 3, $images[1]['id']),
                $v('Silver', '18in', 109, 2, $images[1]['id']),
            ],
        ];
    }

    public function test_upload_stores_file_under_store_folder_and_rejects_non_images(): void
    {
        $img = $this->upload($this->a);
        $this->assertSame(800, $img['width']);
        $path = ProductImage::withoutGlobalScope('store')->find($img['id'])->path;
        $this->assertStringStartsWith("stores/{$this->a['store_id']}/products/", $path);
        Storage::disk('public')->assertExists($path);

        $this->as($this->a)->post('/api/admin/uploads/images', [
            'file' => UploadedFile::fake()->create('evil.php.jpg', 10, 'text/x-php'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_create_product_with_images_options_and_variants(): void
    {
        $img1 = $this->upload($this->a, 'gold.jpg');
        $img2 = $this->upload($this->a, 'silver.jpg');

        $res = $this->as($this->a)->postJson('/api/admin/products', $this->necklacePayload([$img1, $img2]))->assertCreated();

        $res->assertJsonPath('data.images.0.id', $img1['id'])
            ->assertJsonPath('data.featured_image', $img1['url'])
            ->assertJsonPath('data.options.1.name', 'Length')
            ->assertJsonCount(4, 'data.variants')
            ->assertJsonPath('data.variants.2.title', 'Silver / 16in')
            ->assertJsonPath('data.variants.2.price', '99.00')
            ->assertJsonPath('data.variants.2.image_id', $img2['id'])
            ->assertJsonPath('data.price', '99.00')       // min
            ->assertJsonPath('data.price_max', '139.00')
            ->assertJsonPath('data.inventory', 10);
    }

    public function test_update_reorders_images_deletes_removed_and_syncs_variants(): void
    {
        $img1 = $this->upload($this->a);
        $img2 = $this->upload($this->a);
        $created = $this->as($this->a)->postJson('/api/admin/products', $this->necklacePayload([$img1, $img2]))->json('data');
        $goldShortId = $created['variants'][0]['id'];
        $img1Path = ProductImage::withoutGlobalScope('store')->find($img1['id'])->path;

        // Drop image 1 and the "Silver" material; keep Gold variants (by id) with new prices
        $res = $this->as($this->a)->putJson("/api/admin/products/{$created['id']}", [
            'images' => [['id' => $img2['id']]],
            'options' => [['name' => 'Material', 'values' => ['Gold']], ['name' => 'Length', 'values' => ['16in', '18in']]],
            'variants' => [
                ['id' => $goldShortId, 'options' => ['Material' => 'Gold', 'Length' => '16in'], 'price' => 119, 'inventory' => 7],
                ['id' => $created['variants'][1]['id'], 'options' => ['Material' => 'Gold', 'Length' => '18in'], 'price' => 125, 'inventory' => 1],
            ],
        ])->assertOk();

        $res->assertJsonCount(1, 'data.images')
            ->assertJsonPath('data.featured_image', $img2['url'])
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonPath('data.variants.0.id', $goldShortId)
            ->assertJsonPath('data.variants.0.price', '119.00')
            ->assertJsonPath('data.variants.0.image_id', null); // its image was removed

        Storage::disk('public')->assertMissing($img1Path);
        $this->assertDatabaseMissing('product_images', ['id' => $img1['id']]);
    }

    public function test_variant_validation(): void
    {
        $base = ['title' => 'Ring', 'options' => [['name' => 'Size', 'values' => ['6', '7']]]];

        // Value not in the option's values
        $this->as($this->a)->postJson('/api/admin/products', $base + ['variants' => [
            ['options' => ['Size' => '9'], 'price' => 10],
        ]])->assertStatus(422);

        // Duplicate combination
        $this->as($this->a)->postJson('/api/admin/products', $base + ['variants' => [
            ['options' => ['Size' => '6'], 'price' => 10], ['options' => ['Size' => '6'], 'price' => 12],
        ]])->assertStatus(422)->assertJsonValidationErrors('variants.1');

        // Several SKUs without options
        $this->as($this->a)->postJson('/api/admin/products', ['title' => 'Ring', 'options' => [], 'variants' => [
            ['price' => 10], ['price' => 12],
        ]])->assertStatus(422);

        // More than 3 options
        $this->as($this->a)->postJson('/api/admin/products', ['title' => 'Ring', 'options' => array_fill(0, 4, ['name' => 'X', 'values' => ['a']]), 'variants' => [['price' => 1]]])
            ->assertStatus(422);

        $this->assertDatabaseCount('products', 0); // nothing half-saved
    }

    public function test_cannot_use_another_stores_images(): void
    {
        $b = $this->register('b@example.com', 'Shop B');
        $foreign = $this->upload($b);

        $this->as($this->a)->postJson('/api/admin/products', [
            'title' => 'Thief', 'images' => [['id' => $foreign['id']]],
        ])->assertStatus(422);

        $this->as($this->a)->postJson('/api/admin/products', [
            'title' => 'Thief 2', 'options' => [], 'variants' => [['price' => 1, 'image_id' => $foreign['id']]],
        ])->assertStatus(422);

        $this->assertNull(ProductImage::withoutGlobalScope('store')->find($foreign['id'])->product_id);
    }

    public function test_storefront_shows_options_and_buys_the_selected_variant(): void
    {
        $img1 = $this->upload($this->a);
        $img2 = $this->upload($this->a);
        $p = $this->as($this->a)->postJson('/api/admin/products', $this->necklacePayload([$img1, $img2]))->json('data');
        $silverLong = collect($p['variants'])->firstWhere('title', 'Silver / 18in');

        $host = 'http://shop-a.lumee.localhost';
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->get("$host/")->assertSee('From $99.00');

        // Default selection = first in-stock variant (Gold / 16in)
        $this->get("$host/products/butterfly-necklace")->assertOk()
            ->assertSee('Material')->assertSee('Silver')->assertSee('18in')
            ->assertSee('$129.00');

        $this->get("$host/products/butterfly-necklace?variant={$silverLong['id']}")->assertSee('$109.00')->assertSee('Only 2 left');

        $res = $this->post("$host/cart/add", ['variant_id' => $silverLong['id'], 'quantity' => 2])->assertRedirect('/cart');
        $cookie = $res->getCookie('lumee_cart', false)->getValue();

        $this->withUnencryptedCookie('lumee_cart', $cookie)->get("$host/cart")
            ->assertSee('Silver / 18in')->assertSee('$218.00')->assertSee(basename($img2['url']));   // a resized copy of it

        // Sold-out variant (Gold / 18in) can't be added
        $goldLong = collect($p['variants'])->firstWhere('title', 'Gold / 18in');
        $this->post("$host/cart/add", ['variant_id' => $goldLong['id']])->assertSessionHasErrors('quantity');
    }
}
