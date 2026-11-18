<?php

namespace App\Catalog;

use App\Models\Order;
use App\Models\OrderDownload;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\Store;
use App\Notifications\DigitalDownloadMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Digital products: when an order is paid, each digital line gets download links for its product's
 * files (5 downloads each, valid 30 days; the merchant can reset them). The buyer gets them by email
 * and on the order page. Orders with only digital (or gift card) items are marked fulfilled.
 */
class DigitalDelivery
{
    public const MAX_DOWNLOADS = 5;

    public const DAYS = 30;

    public function deliver(Order $order): int
    {
        $order->loadMissing('items');
        $digital = Product::withoutGlobalScope('store')->whereIn('id', $order->items->pluck('product_id')->filter())->where('digital', true)->pluck('id');
        if ($digital->isEmpty() || OrderDownload::withoutGlobalScope('store')->where('order_id', $order->id)->exists()) {
            return 0;
        }
        $files = ProductFile::withoutGlobalScope('store')->whereIn('product_id', $digital)->get();
        foreach ($files as $f) {
            OrderDownload::withoutGlobalScope('store')->create([
                'store_id' => $order->store_id, 'order_id' => $order->id, 'product_file_id' => $f->id, 'token' => Str::random(48),
                'max_downloads' => self::MAX_DOWNLOADS, 'expires_at' => now()->addDays(self::DAYS),
            ]);
        }
        if ($files->isNotEmpty()) {
            $order->log('digital', __('已发送 :p0 个下载链接给买家', ['p0' => $files->count()]));
            try {
                $store = Store::find($order->store_id);
                Mail::to($order->email)->locale($order->locale ?: \App\I18n\Localization::storeDefault($store))->send(new DigitalDownloadMail($order, $store));
            } catch (\Throwable $e) {
                report($e);
            }
        }
        // Nothing to ship: the order is done
        $physical = Product::withoutGlobalScope('store')->whereIn('id', $order->items->pluck('product_id')->filter())
            ->where('digital', false)->where('is_gift_card', false)->exists();
        if (! $physical && $order->fulfillment_status !== 'fulfilled') {
            $order->forceFill(['fulfillment_status' => 'fulfilled', 'fulfilled_at' => now()])->save();
            $order->log('fulfilled', __('数字商品已交付'));
        }

        return $files->count();
    }

    /** Fresh links (e.g. the buyer used them up or they expired). */
    public function reset(Order $order): void
    {
        OrderDownload::withoutGlobalScope('store')->where('order_id', $order->id)
            ->update(['downloads' => 0, 'expires_at' => now()->addDays(self::DAYS)]);
        $order->log('digital', __('重置了下载链接'));
    }

    /** @return array<int, array{filename:string, url:string, left:int, expires_at:mixed, usable:bool}> */
    public static function links(Order $order, string $base): array
    {
        return OrderDownload::withoutGlobalScope('store')->with('file')->where('order_id', $order->id)->get()
            ->filter(fn ($d) => $d->file)->map(fn (OrderDownload $d) => [
                'filename' => $d->file->filename, 'url' => rtrim($base, '/').'/downloads/'.$d->token,
                'left' => max(0, $d->max_downloads - $d->downloads), 'expires_at' => $d->expires_at, 'usable' => $d->usable(), 'downloads' => $d->downloads,
            ])->values()->all();
    }
}
