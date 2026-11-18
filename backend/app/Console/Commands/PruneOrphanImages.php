<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use Illuminate\Console\Command;

/**
 * Images are uploaded before the product is saved. If the merchant leaves without saving,
 * the upload is never attached — this removes those records and their files.
 */
class PruneOrphanImages extends Command
{
    protected $signature = 'lumee:prune-images {--hours=24 : Only delete uploads older than this}';

    protected $description = 'Delete product images that were uploaded but never attached to a product';

    public function handle(): int
    {
        $cutoff = now()->subHours((int) $this->option('hours'));
        $count = 0;

        ProductImage::withoutGlobalScope('store')
            ->whereNull('product_id')
            ->where('created_at', '<=', $cutoff)
            ->chunkById(200, function ($images) use (&$count) {
                foreach ($images as $image) {
                    $image->delete(); // also removes the file (ProductImage::booted)
                    $count++;
                }
            });

        $this->info("Deleted {$count} orphan image(s).");

        return self::SUCCESS;
    }
}
