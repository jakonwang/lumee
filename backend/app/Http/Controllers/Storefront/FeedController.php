<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\I18n\Localization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Seo;
use App\Tenancy\CurrentStore;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Google Merchant Center product feed (RSS 2.0 + g: namespace), one item per variant.
 * https://support.google.com/merchants/answer/7052112
 * /feeds/google.xml in the default language; /fr/feeds/google.xml etc. for translated feeds.
 */
class FeedController extends Controller
{
    public function google(CurrentStore $current, Localization $l10n): Response
    {
        $store = $current->getOrFail();
        $base = $store->storefrontUrl().$l10n->prefix();
        $abs = fn (?string $url) => $url === null || $url === '' ? null
            : (Str::startsWith($url, ['http://', 'https://']) ? $url : $store->storefrontUrl().'/'.ltrim($url, '/'));
        $x = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $money = fn (int $minor) => number_format($minor / 100, 2, '.', '').' '.$store->currency;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>'."\n"
            .'<title>'.$x($store->name).'</title><link>'.$x($base.'/').'</link><description>'.$x($store->name).'</description>'."\n";

        Product::active()->with(['variants.image', 'images'])->orderBy('id')->chunk(200, function ($products) use (&$xml, $base, $abs, $x, $money, $store) {
            foreach ($products as $p) {
                $description = Seo::text($p->description, 5000) ?: $p->title;
                $extra = $p->images->skip(1)->take(10)->map(fn ($i) => $i->url())->all();
                foreach ($p->variants as $v) {
                    /** @var ProductVariant $v */
                    $multi = $p->variants->count() > 1;
                    $title = $multi && $v->title !== 'Default' ? $p->title.' - '.$v->title : $p->title;
                    $image = $abs($v->image?->url() ?? $p->featured_image ?? $p->images->first()?->url());
                    $onSale = $v->compare_at_price && $v->compare_at_price > $v->price;

                    $xml .= '<item>'
                        .'<g:id>'.$x($v->sku ?: 'V'.$v->id).'</g:id>'
                        .($multi ? '<g:item_group_id>'.$x('P'.$p->id).'</g:item_group_id>' : '')
                        .'<g:title>'.$x(Str::limit($title, 150, '')).'</g:title>'
                        .'<g:description>'.$x($description).'</g:description>'
                        .'<g:link>'.$x($base.'/products/'.rawurlencode($p->handle).($multi ? '?variant='.$v->id : '')).'</g:link>'
                        .($image ? '<g:image_link>'.$x($image).'</g:image_link>' : '')
                        .implode('', array_map(fn ($u) => '<g:additional_image_link>'.$x($abs($u)).'</g:additional_image_link>', $extra))
                        .'<g:availability>'.($v->inventory_quantity > 0 ? 'in_stock' : 'out_of_stock').'</g:availability>'
                        .'<g:price>'.$x($money((int) ($onSale ? $v->compare_at_price : $v->price))).'</g:price>'
                        .($onSale ? '<g:sale_price>'.$x($money((int) $v->price)).'</g:sale_price>' : '')
                        .'<g:brand>'.$x($p->vendor ?: $store->name).'</g:brand>'
                        .'<g:condition>new</g:condition>'
                        .($v->sku ? '<g:mpn>'.$x($v->sku).'</g:mpn>' : '<g:identifier_exists>no</g:identifier_exists>')
                        .($p->product_type ? '<g:product_type>'.$x($p->product_type).'</g:product_type>' : '')
                        .($v->weight ? '<g:shipping_weight>'.$x($v->weight.' g').'</g:shipping_weight>' : '')
                        ."</item>\n";
                }
            }
        });
        $xml .= "</channel></rss>\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=900']);
    }
}
