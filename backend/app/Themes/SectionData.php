<?php

namespace App\Themes;

use App\Markets\MarketContext;
use App\Models\Collection;
use App\Models\Discount;
use App\Models\Product;
use App\Models\ProductReview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection as Items;
use Illuminate\Support\Facades\DB;

/**
 * Data the storefront blocks need (products, collections, coupons, trust items…), looked up by the
 * block itself so any block works on any page (home, landing pages, product / collection / cart).
 * Everything respects the current market: its products, prices, currency, payment methods.
 * Request-scoped; results are memoized per call signature.
 */
class SectionData
{
    public const VARIANTS = 'variants:id,product_id,price,compare_at_price,inventory_quantity,position';

    private array $memo = [];

    private function memo(string $key, callable $fn): mixed
    {
        return $this->memo[$key] ??= $fn();
    }

    /** newest | bestsellers | sale | collection */
    public function products(string $source, ?int $collectionId, int $limit, int $days = 30): Items
    {
        return $this->memo("p|$source|$collectionId|$limit|$days", fn () => match ($source) {
            'bestsellers' => $this->bestsellers($days, $limit),
            'sale' => Product::active()->with(self::VARIANTS)
                ->whereHas('variants', fn ($q) => $q->whereColumn('compare_at_price', '>', 'price'))->latest('id')->take($limit)->get(),
            'collection' => ($c = $collectionId ? Collection::published()->find($collectionId) : null)
                ? $c->sortedProducts()->with(self::VARIANTS)->take($limit)->get() : collect(),
            default => Product::active()->with(self::VARIANTS)->latest('id')->take($limit)->get(),
        });
    }

    /**
     * What sells in the current market (its own orders; the default site counts orders without a market)
     * over the last $days, by units. Topped up with the newest products while there's little data.
     */
    public function bestsellers(int $days, int $limit): Items
    {
        return $this->memo("b|$days|$limit", function () use ($days, $limit) {
            $marketId = app(MarketContext::class)->market()?->id;
            $ids = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.store_id', app(\App\Tenancy\CurrentStore::class)->id())
                ->when($marketId, fn ($q) => $q->where('orders.market_id', $marketId), fn ($q) => $q->whereNull('orders.market_id'))
                ->whereNull('orders.cancelled_at')->where('orders.created_at', '>=', now()->subDays($days))
                ->whereNotNull('order_items.product_id')
                ->groupBy('order_items.product_id')->orderByRaw('SUM(order_items.quantity) DESC')
                ->limit($limit * 2)->pluck('order_items.product_id')->all();
            $products = $ids ? Product::active()->with(self::VARIANTS)->whereIn('id', $ids)->get()
                ->sortBy(fn ($p) => array_search($p->id, $ids))->values()->take($limit) : collect();
            if ($products->count() < $limit) {
                $products = $products->concat(Product::active()->with(self::VARIANTS)->whereNotIn('id', $products->pluck('id'))
                    ->latest('id')->take($limit - $products->count())->get());
            }

            return $products->values();
        });
    }

    /** The chosen collections in order, else the first $limit published ones. @param array<int|null> $ids */
    public function collections(array $ids, int $limit): Items
    {
        $ids = array_values(array_filter($ids));

        return $this->memo('c|'.implode(',', $ids)."|$limit", fn () => $ids
            ? Collection::published()->with('image')->whereIn('id', $ids)->get()->sortBy(fn ($c) => array_search($c->id, $ids))->values()
            : Collection::published()->with('image')->orderBy('title')->take($limit)->get());
    }

    /**
     * Flash sale: products (a collection, else what's on sale) and when it ends — the block's own time
     * (store time zone), else the soonest-ending running automatic promotion. Null once it's over.
     *
     * @return array{products: Items, ends_at: CarbonImmutable}|null
     */
    public function flashSale(array $s): ?array
    {
        $tz = app(\App\Tenancy\CurrentStore::class)->getOrFail()->timezone ?: 'UTC';
        $ends = $s['ends_at'] ? CarbonImmutable::parse($s['ends_at'], $tz)
            : \App\Models\Promotion::where('enabled', true)->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where('ends_at', '>', now())->orderBy('ends_at')->value('ends_at');
        $ends = $ends ? CarbonImmutable::parse($ends) : null;
        if (! $ends || $ends->isPast()) {
            return null;
        }
        $products = $this->products($s['collection'] ? 'collection' : 'sale', $s['collection'], (int) $s['limit']);

        return $products->isEmpty() ? null : ['products' => $products, 'ends_at' => $ends];
    }

    /** Coupon codes that work right now, with what they give. @return array<int, array{code: string, label: string}> */
    public function coupons(array $s): array
    {
        $out = [];
        $currency = app(\App\Tenancy\CurrentStore::class)->getOrFail()->currency;   // market currency inside a market
        foreach ([1, 2, 3] as $n) {
            $code = trim((string) ($s["code$n"] ?? ''));
            $d = $code !== '' ? Discount::where('code', $code)->first() : null;
            if (! $d || $d->status() !== 'active') {
                continue;   // only codes shoppers can actually use
            }
            $label = trim((string) ($s["label$n"] ?? ''));
            if ($label === '') {
                $label = $d->type === 'fixed'
                    ? __(':amount off', ['amount' => \App\Support\Money::display(app(MarketContext::class)->money($d->value), $currency)])
                    : $d->label($currency);
                if ($d->min_subtotal) {
                    $label .= ' · '.__('Spend :amount or more to use this code.', ['amount' => \App\Support\Money::display(app(MarketContext::class)->money($d->min_subtotal), $currency)]);
                }
            }
            $out[] = ['code' => $d->code, 'label' => $label];
        }

        return $out;
    }

    /** Trust items for the current market: payment methods, free-shipping threshold, duties, returns. @return string[] */
    public function trust(array $s): array
    {
        $store = app(\App\Tenancy\CurrentStore::class)->getOrFail();
        $items = [];
        if ($s['show_shipping']) {
            $min = \App\Models\ShippingRate::where('price', 0)->whereNotNull('min_subtotal')->min('min_subtotal');
            if ($min) {
                $items[] = __('Free shipping over :amount', ['amount' => \App\Support\Money::display(app(MarketContext::class)->money((int) $min), $store->currency)]);
            }
        }
        if ($s['returns'] !== '') {
            $items[] = $s['returns'];
        }
        if ($s['show_duties'] && ($market = app(MarketContext::class)->market())) {
            $mode = \App\Markets\MarketSettings::of($market)['duties']['mode'];
            if ($mode === 'included') {
                $items[] = __('Duties included');
            }
        }
        if ($s['show_payments']) {
            $names = $this->paymentNames();
            if ($names) {
                $items[] = __('Pay with :methods', ['methods' => implode(' · ', $names)]);
            }
        }

        return $items;
    }

    /** Payment methods shoppers in the current market can use. @return string[] */
    public function paymentNames(): array
    {
        return $this->memo('pay', function () {
            $store = app(\App\Tenancy\CurrentStore::class)->getOrFail();
            $gateways = app(\App\Payments\GatewayRegistry::class)->available();
            $names = [];
            foreach ($gateways as $key => $g) {
                $names[] = match ($key) {
                    'stripe' => 'Visa · Mastercard',
                    'paypal' => 'PayPal',
                    'cod' => __('Cash on delivery'),
                    'test', 'manual', 'terms' => null,
                    default => $g->label(),
                };
                if ($key === 'stripe') {
                    foreach (\App\Payments\PaymentSettings::for($store)->stripeMethods($store->currency) as $m) {
                        $names[] = \App\Payments\PaymentSettings::STRIPE_METHODS[$m][0];
                    }
                }
            }
            if (\App\Payments\Installments::settings()) {
                $names[] = __('Installments');
            }

            return array_values(array_unique(array_filter($names)));
        });
    }

    /** Customer photos from published reviews. @return array<int, array{url: string, author: string, rating: int, product: ?Product}> */
    public function ugc(int $limit, int $minRating): array
    {
        return $this->memo("u|$limit|$minRating", function () use ($limit, $minRating) {
            $reviews = ProductReview::published()->where('rating', '>=', $minRating)->whereNotNull('images')->latest('id')->limit($limit * 2)->get();
            $products = Product::active()->whereIn('id', $reviews->pluck('product_id'))->get(['id', 'title', 'handle'])->keyBy('id');
            $out = [];
            foreach ($reviews as $r) {
                foreach ($r->imageUrls() as $url) {
                    $out[] = ['url' => $url, 'author' => $r->displayName(), 'rating' => (int) $r->rating, 'product' => $products->get($r->product_id)];
                    if (count($out) >= $limit) {
                        return $out;
                    }
                }
            }

            return $out;
        });
    }

    /** Products for hotspots, by id (only ones on sale here). @param array<int|null> $ids */
    public function productsById(array $ids): Items
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids ? Product::active()->with(self::VARIANTS)->whereIn('id', $ids)->get()->keyBy('id') : collect();
    }

    /** "Order now, arrives between …" for the shipping estimate block (business days skipped). @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function deliveryWindow(int $processing, int $min, int $max): array
    {
        $add = function (CarbonImmutable $d, int $days): CarbonImmutable {
            while ($days > 0) {
                $d = $d->addDay();
                if (! $d->isWeekend()) {
                    $days--;
                }
            }

            return $d;
        };
        $tz = app(\App\Tenancy\CurrentStore::class)->getOrFail()->timezone ?: 'UTC';
        $start = $add(CarbonImmutable::now($tz), $processing);

        return [$add($start, $min), $add($start, max($min, $max))];
    }
}
