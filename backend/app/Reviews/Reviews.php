<?php

namespace App\Reviews;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Product reviews: shopper submissions (with photos), moderation and the product's
 * cached rating. Settings in stores.settings['reviews']:
 *   enabled       show reviews and the "write a review" form on product pages
 *   auto_publish  all | verified (bought the product) | none (every review waits for approval)
 */
class Reviews
{
    public const DEFAULTS = ['enabled' => true, 'auto_publish' => 'verified', 'request_delay_days' => 7];

    /** Days after shipping before the "how was it?" email (the email itself is switched in notification settings). */
    public const REQUEST_DELAYS = [3, 7, 14, 30];

    public const AUTO_PUBLISH = ['all', 'verified', 'none'];

    public static function settings(Store $store): array
    {
        $s = array_merge(self::DEFAULTS, array_intersect_key($store->settings['reviews'] ?? [], self::DEFAULTS));
        $s['enabled'] = (bool) $s['enabled'];
        $s['auto_publish'] = in_array($s['auto_publish'], self::AUTO_PUBLISH, true) ? $s['auto_publish'] : self::DEFAULTS['auto_publish'];
        $s['request_delay_days'] = in_array((int) $s['request_delay_days'], self::REQUEST_DELAYS, true) ? (int) $s['request_delay_days'] : self::DEFAULTS['request_delay_days'];

        return $s;
    }

    public static function saveSettings(Store $store, array $data): void
    {
        $settings = $store->settings ?? [];
        $settings['reviews'] = [
            'enabled' => (bool) $data['enabled'],
            'auto_publish' => $data['auto_publish'],
            'request_delay_days' => (int) ($data['request_delay_days'] ?? self::DEFAULTS['request_delay_days']),
        ];
        $store->settings = $settings;
        $store->save();
    }

    /**
     * A shopper's review from the product page.
     * @param  UploadedFile[]  $photos
     */
    public function submit(Store $store, Product $product, array $data, array $photos, ?Customer $customer): ProductReview
    {
        $email = mb_strtolower(trim($data['email']));
        $verified = Order::where('email', $email)
            ->whereIn('financial_status', ['paid', 'partially_refunded'])
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
            ->exists();

        $auto = self::settings($store)['auto_publish'];
        $status = $auto === 'all' || ($auto === 'verified' && $verified) ? 'published' : 'pending';

        $images = [];
        foreach (array_slice($photos, 0, 3) as $file) {
            $disk = config('lumee.media_disk');
            $path = $file->storeAs("stores/{$store->id}/reviews", Str::uuid().'.'.strtolower($file->extension()), ['disk' => $disk, 'visibility' => 'public']);
            $images[] = ['disk' => $disk, 'path' => $path];
        }

        $review = ProductReview::create([
            'product_id' => $product->id,
            'customer_id' => $customer?->id,
            'author' => trim($data['author']),
            'email' => $email,
            'rating' => (int) $data['rating'],
            'title' => filled($data['title'] ?? null) ? trim($data['title']) : null,
            'body' => trim($data['body']),
            'images' => $images ?: null,
            'status' => $status,
            'verified' => $verified,
            'source' => 'storefront',
            'locale' => app(\App\I18n\Localization::class)->locale(),
        ]);
        $this->recount($product->id);

        return $review;
    }

    /** Refresh the product's average rating and count from its published reviews. */
    public function recount(int $productId): void
    {
        $row = ProductReview::withoutGlobalScope('store')->where('product_id', $productId)->published()
            ->select(DB::raw('COUNT(*) as n'), DB::raw('AVG(rating) as avg'))->first();
        Product::withoutGlobalScope('store')->whereKey($productId)->update([
            'rating_count' => (int) $row->n,
            'rating_avg' => $row->n ? round((float) $row->avg, 2) : null,
        ]);
    }
}
