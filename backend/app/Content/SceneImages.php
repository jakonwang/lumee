<?php

namespace App\Content;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AI scene and model pictures from a real product photo (content workshop). The product itself must
 * stay exactly as photographed — colour, shape, material, logo — so pictures don't mislead
 * shoppers (and raise returns); the service only builds the scene around it. Each picture is added
 * as a new image, marked as AI-made, and counts against the store's monthly allowance.
 *
 * Settings (stores.settings['brand_kit']): image_ai_provider, image_ai_key (encrypted),
 * image_ai_quota (pictures per month), image_ai_usage {month, count}.
 */
class SceneImages
{
    /** Provider key => [name, class]. */
    public const PROVIDERS = ['openai' => ['OpenAI (gpt-image-1)', OpenAiImages::class]];

    public const SCENES = [
        'lifestyle' => 'a natural lifestyle setting where this product is typically used, soft daylight, shallow depth of field',
        'studio' => 'a clean studio set with a seamless backdrop in the brand background colour and soft shadows',
        'flatlay' => 'a styled flat lay from directly above with a few complementary props',
        'model' => 'worn or held by a person in a natural pose (the face may be out of frame), the product clearly visible',
        'holiday' => 'a seasonal gift setting with subtle festive decoration',
    ];

    public const DEFAULT_QUOTA = 50;

    public function __construct(private Store $store) {}

    public static function for(Store $store): self
    {
        return new self($store);
    }

    /** @return array{provider: string, connected: bool, quota: int, used: int, providers: array} */
    public function status(): array
    {
        $k = (array) ($this->store->settings['brand_kit'] ?? []);

        return [
            'provider' => isset(self::PROVIDERS[$k['image_ai_provider'] ?? '']) ? $k['image_ai_provider'] : 'openai',
            'connected' => ! empty($k['image_ai_key']),
            'quota' => (int) ($k['image_ai_quota'] ?? self::DEFAULT_QUOTA),
            'used' => $this->used(),
            'providers' => collect(self::PROVIDERS)->map(fn ($p, $key) => ['key' => $key, 'name' => $p[0]])->values()->all(),
        ];
    }

    public function generator(): ?ImageGenerator
    {
        $k = (array) ($this->store->settings['brand_kit'] ?? []);
        $key = ! empty($k['image_ai_key']) ? rescue(fn () => Crypt::decryptString($k['image_ai_key']), null, false) : null;
        if (! $key) {
            return null;
        }
        $class = self::PROVIDERS[$this->status()['provider']][1];

        return new $class($key);
    }

    /** Make a scene picture from one of the product's photos; returns the new image (last position). */
    public function generate(Product $product, ProductImage $source, string $scene, ?string $note = null): ProductImage
    {
        $status = $this->status();
        $gen = $this->generator();
        if (! $gen) {
            throw new RuntimeException(__('先在「品牌规范」里填写图片生成服务的密钥'));
        }
        if ($status['used'] >= $status['quota']) {
            throw new RuntimeException(__('本月的 AI 图片额度（:p0 张）已用完，可以在「品牌规范」里调整', ['p0' => $status['quota']]));
        }
        $bytes = Storage::disk($source->disk)->get($source->original_path ?: $source->path);
        if (! $bytes) {
            throw new RuntimeException(__('图片文件不存在'));
        }

        $out = $gen->edit($bytes, $this->prompt($product, $scene, $note), '1024x1536');
        $this->count();

        $info = @getimagesizefromstring($out);
        $ext = ['image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? 'png';
        $path = "stores/{$this->store->id}/products/".Str::uuid().'.'.$ext;
        Storage::disk($source->disk)->put($path, $out, ['visibility' => 'public']);
        $img = new ProductImage([
            'product_id' => $product->id, 'disk' => $source->disk, 'path' => $path, 'alt' => $product->title,
            'width' => $info[0] ?? null, 'height' => $info[1] ?? null, 'size' => strlen($out),
            'position' => (int) $product->images()->max('position') + 1,
        ]);
        $img->forceFill(['store_id' => $product->store_id, 'processed' => ['ai' => 'scene', 'scene' => $scene, 'from_image' => $source->id, 'note' => $note, 'at' => now()->toIso8601String()]])->save();

        return $img;
    }

    public function prompt(Product $product, string $scene, ?string $note): string
    {
        $kit = BrandKit::for($this->store)->all();
        $parts = [
            'Product photo for an online store. Keep the product from the input image exactly as it is: same shape, colour, material, proportions, details, text and logo. Do not redesign, recolour or add anything to the product itself.',
            'Place it in '.(self::SCENES[$scene] ?? self::SCENES['lifestyle']).'.',
            'Product: '.Str::limit($product->title, 120).($product->product_type ? ' ('.$product->product_type.')' : '').'.',
            $kit['positioning'] !== '' ? 'Brand: '.Str::limit($kit['positioning'], 200).'.' : null,
            $kit['audience'] !== '' ? 'Audience: '.Str::limit($kit['audience'], 200).'.' : null,
            'Brand background colour: '.$kit['background'].'. Photorealistic, high resolution, no text, no watermark, no other brands.',
            $note ? 'Extra direction: '.Str::limit($note, 300) : null,
        ];

        return implode(' ', array_filter($parts));
    }

    public function save(array $data): void
    {
        $settings = $this->store->settings ?? [];
        $kit = (array) ($settings['brand_kit'] ?? []);
        if (isset($data['image_ai_provider']) && isset(self::PROVIDERS[$data['image_ai_provider']])) {
            $kit['image_ai_provider'] = $data['image_ai_provider'];
        }
        if (isset($data['image_ai_quota'])) {
            $kit['image_ai_quota'] = max(0, min(10000, (int) $data['image_ai_quota']));
        }
        // "" removes the key, null/absent keeps it
        if (array_key_exists('image_ai_key', $data) && $data['image_ai_key'] !== null) {
            $kit['image_ai_key'] = $data['image_ai_key'] === '' ? null : Crypt::encryptString($data['image_ai_key']);
        }
        $settings['brand_kit'] = $kit;
        $this->store->settings = $settings;
        $this->store->save();
    }

    /** Count one picture against this month's allowance (also used for AI banner backgrounds). */
    public function charge(): void
    {
        $this->count();
    }

    private function used(): int
    {
        $u = (array) ($this->store->settings['brand_kit']['image_ai_usage'] ?? []);

        return ($u['month'] ?? null) === now()->format('Y-m') ? (int) ($u['count'] ?? 0) : 0;
    }

    private function count(): void
    {
        $this->store->refresh();
        $settings = $this->store->settings ?? [];
        $settings['brand_kit']['image_ai_usage'] = ['month' => now()->format('Y-m'), 'count' => $this->used() + 1];
        $this->store->settings = $settings;
        $this->store->save();
    }
}
