<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\I18n\Localization;
use App\Markets\MarketContext;
use App\Models\Product;
use App\Models\ProductMarketFit;
use App\Models\ProductVariant;
use App\Support\Seo;
use App\Tenancy\CurrentStore;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Product feeds for ad platforms, one item per variant, in the language and market of the URL:
 *
 *   /feeds/google.xml   Google Merchant Center (RSS 2.0 + g: namespace)  https://support.google.com/merchants/answer/7052112
 *   /feeds/meta.xml     Meta Commerce Manager catalog (same RSS format, Meta's availability values)
 *   /feeds/tiktok.csv   TikTok catalog (CSV data feed)
 *
 * /fr/feeds/… is the French feed; /es-mx/feeds/… the Mexico market: its products, its currency and
 * prices (fixed market prices included). Custom labels let merchants bid by what sells best:
 *   custom_label_0  market fit in this market (fit-high / fit-medium / fit-low / fit-unknown)
 *   custom_label_1  test & learn result (hot / testing / cold …)
 *   custom_label_2  margin band (margin-high ≥ 50% / margin-mid ≥ 30% / margin-low)
 */
class FeedController extends Controller
{
    public function google(CurrentStore $current, Localization $l10n): Response
    {
        return $this->rss($current, $l10n, 'google');
    }

    public function meta(CurrentStore $current, Localization $l10n): Response
    {
        return $this->rss($current, $l10n, 'meta');
    }

    public function tiktok(CurrentStore $current, Localization $l10n): StreamedResponse
    {
        $columns = ['sku_id', 'title', 'description', 'availability', 'condition', 'price', 'sale_price', 'link', 'image_link', 'additional_image_link',
            'brand', 'item_group_id', 'product_type', 'custom_label_0', 'custom_label_1', 'custom_label_2'];

        return response()->stream(function () use ($current, $l10n, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            $this->each($current, $l10n, function (array $i) use ($out) {
                fputcsv($out, [$i['id'], $i['title'], $i['description'], $i['in_stock'] ? 'in stock' : 'out of stock', 'new', $i['price'], $i['sale_price'] ?? '',
                    $i['link'], $i['image'] ?? '', implode(',', $i['extra_images']), $i['brand'], $i['group'] ?? '', $i['product_type'] ?? '',
                    $i['labels'][0], $i['labels'][1], $i['labels'][2]]);
            });
            fclose($out);
        }, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'public, max-age=900']);
    }

    private function rss(CurrentStore $current, Localization $l10n, string $platform): Response
    {
        $store = $current->getOrFail();
        $x = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>'."\n"
            .'<title>'.$x($store->name).'</title><link>'.$x($store->storefrontUrl().$l10n->prefix().'/').'</link><description>'.$x($store->name).'</description>'."\n";

        $this->each($current, $l10n, function (array $i) use (&$xml, $x, $platform) {
            $availability = $platform === 'meta' ? ($i['in_stock'] ? 'in stock' : 'out of stock') : ($i['in_stock'] ? 'in_stock' : 'out_of_stock');
            $xml .= '<item>'
                .'<g:id>'.$x($i['id']).'</g:id>'
                .($i['group'] ? '<g:item_group_id>'.$x($i['group']).'</g:item_group_id>' : '')
                .'<g:title>'.$x($i['title']).'</g:title>'
                .'<g:description>'.$x($i['description']).'</g:description>'
                .'<g:link>'.$x($i['link']).'</g:link>'
                .($i['image'] ? '<g:image_link>'.$x($i['image']).'</g:image_link>' : '')
                .implode('', array_map(fn ($u) => '<g:additional_image_link>'.$x($u).'</g:additional_image_link>', $i['extra_images']))
                .'<g:availability>'.$availability.'</g:availability>'
                .'<g:price>'.$x($i['price']).'</g:price>'
                .($i['sale_price'] ? '<g:sale_price>'.$x($i['sale_price']).'</g:sale_price>' : '')
                .'<g:brand>'.$x($i['brand']).'</g:brand>'
                .'<g:condition>new</g:condition>'
                .($i['sku'] ? '<g:mpn>'.$x($i['sku']).'</g:mpn>' : ($platform === 'google' ? '<g:identifier_exists>no</g:identifier_exists>' : ''))
                .($i['product_type'] ? '<g:product_type>'.$x($i['product_type']).'</g:product_type>' : '')
                .($i['weight'] ? '<g:shipping_weight>'.$x($i['weight'].' g').'</g:shipping_weight>' : '')
                .'<g:custom_label_0>'.$x($i['labels'][0]).'</g:custom_label_0>'
                .'<g:custom_label_1>'.$x($i['labels'][1]).'</g:custom_label_1>'
                .'<g:custom_label_2>'.$x($i['labels'][2]).'</g:custom_label_2>'
                ."</item>\n";
        });
        $xml .= "</channel></rss>\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=900']);
    }

    /** Every variant on sale here, with prices in this market's currency. */
    private function each(CurrentStore $current, Localization $l10n, callable $fn): void
    {
        $store = $current->getOrFail();
        $market = app(MarketContext::class)->market();
        $base = $store->storefrontUrl().$l10n->prefix();
        $abs = fn (?string $url) => $url === null || $url === '' ? null
            : (Str::startsWith($url, ['http://', 'https://']) ? $url : $store->storefrontUrl().'/'.ltrim($url, '/'));
        $money = fn (int $minor) => number_format($minor / 100, 2, '.', '').' '.$store->currency;   // the market's currency inside a market
        $fits = $market ? ProductMarketFit::where('market_id', $market->id)->pluck('score', 'product_id') : collect();

        Product::active()->with(['variants.image', 'images'])->orderBy('id')->chunk(200, function ($products) use ($fn, $base, $abs, $money, $store, $market, $fits) {
            foreach ($products as $p) {
                $description = Seo::text($p->description, 5000) ?: $p->title;
                $extra = $p->images->skip(1)->take(10)->map(fn ($i) => $abs($i->url()))->filter()->values()->all();
                $fit = $fits->get($p->id);
                $fitLabel = ! $market ? 'default-site' : match (true) {
                    $fit === null => 'fit-unknown', $fit >= 70 => 'fit-high', $fit >= 50 => 'fit-medium', default => 'fit-low',
                };
                $multi = $p->variants->count() > 1;
                foreach ($p->variants as $v) {
                    /** @var ProductVariant $v */
                    // The variant gives the price shoppers here pay (market price, fixed price, promotions)
                    $price = (int) $v->price;
                    $compare = $v->compare_at_price ? (int) $v->compare_at_price : null;
                    $onSale = $compare && $compare > $price;
                    $storePrice = (int) $v->getRawOriginal('price');   // store currency, like the cost
                    $margin = $v->cost && $storePrice > 0 ? ($storePrice - $v->cost) / $storePrice : null;
                    $fn([
                        'id' => $v->sku ?: 'V'.$v->id,
                        'sku' => $v->sku,
                        'group' => $multi ? 'P'.$p->id : null,
                        'title' => Str::limit($multi && $v->title !== 'Default' ? $p->title.' - '.$v->title : $p->title, 150, ''),
                        'description' => $description,
                        'link' => $base.'/products/'.rawurlencode($p->handle).($multi ? '?variant='.$v->id : ''),
                        'image' => $abs($v->image?->url() ?? $p->featured_image ?? $p->images->first()?->url()),
                        'extra_images' => $extra,
                        'in_stock' => $v->inventory_quantity > 0,
                        'price' => $money($onSale ? $compare : $price),
                        'sale_price' => $onSale ? $money($price) : null,
                        'brand' => $p->vendor ?: $store->name,
                        'product_type' => $p->product_type ?: null,
                        'weight' => $v->weight,
                        'labels' => [
                            $fitLabel,
                            $p->test_status ?: 'untested',
                            match (true) { $margin === null => 'margin-unknown', $margin >= 0.5 => 'margin-high', $margin >= 0.3 => 'margin-mid', default => 'margin-low' },
                        ],
                    ]);
                }
            }
        });
    }
}
