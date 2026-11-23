<?php

namespace App\Http\Controllers\Admin;

use App\Currency\ExchangeRates;
use App\Http\Controllers\Controller;
use App\I18n\Localization;
use App\Markets\MarketContext;
use App\Models\Market;
use App\Models\MarketPrice;
use App\Models\MarketProductExclusion;
use App\Models\Product;
use App\Support\Countries;
use App\Support\Money;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Markets (Settings › Markets): country sites with their own currency, languages and URL,
 * plus each product's availability and fixed prices per market (product editor).
 */
class MarketController extends Controller
{
    public function index(CurrentStore $current): JsonResponse
    {
        $store = $current->getOrFail();

        return response()->json([
            'data' => Market::orderBy('position')->orderBy('id')->get()->map(fn (Market $m) => $this->present($m)),
            'store_currency' => $store->baseCurrency(),
            'store_url' => $store->storefrontUrl(),
            'currencies' => config('lumee.currencies'),
            'languages' => collect(Localization::languages())->map(fn ($name, $code) => ['code' => $code, 'name' => $name])->values(),
            'countries' => collect(Countries::all())->map(fn ($name, $code) => ['code' => $code, 'name' => $name])->values(),
            'rounding' => Market::ROUNDING,
            'presets' => \App\Markets\Presets::forList(),
            'duty_modes' => \App\Markets\MarketSettings::DUTY_MODES,
            'live_theme_id' => app(\App\Themes\ThemeService::class)->ensureLive($store)->id,   // to open the editor in a market
        ]);
    }

    /** Localization of one market: import duties, cash on delivery, installments (amounts in major units). Store texts are edited in the theme editor. */
    public function saveLocalization(Request $request, Market $market): JsonResponse
    {
        $data = $request->validate([
            'duties.mode' => ['required', Rule::in(\App\Markets\MarketSettings::DUTY_MODES)],
            'duties.rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'duties.threshold' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'cod.enabled' => ['sometimes', 'boolean'],
            'cod.fee' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'cod.max' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'installments.enabled' => ['sometimes', 'boolean'],
            'installments.months' => ['nullable', 'integer', Rule::in(\App\Payments\Installments::MONTHS)],
            'installments.min' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'installments.interest_free' => ['sometimes', 'boolean'],
        ]);
        $settings = (array) ($market->settings ?? []);
        $settings['duties'] = ['mode' => $data['duties']['mode'], 'rate' => (float) ($data['duties']['rate'] ?? 0), 'threshold' => Money::toMinor($data['duties']['threshold'] ?? 0)];
        if (isset($data['cod'])) {
            $settings['cod'] = ['enabled' => (bool) ($data['cod']['enabled'] ?? false), 'fee' => Money::toMinor($data['cod']['fee'] ?? 0), 'max' => Money::toMinor($data['cod']['max'] ?? 0)];
        }
        if (isset($data['installments'])) {
            $i = $data['installments'];
            $settings['installments'] = ['enabled' => (bool) ($i['enabled'] ?? false), 'months' => (int) ($i['months'] ?? 12),
                'min' => Money::toMinor($i['min'] ?? 0), 'interest_free' => (bool) ($i['interest_free'] ?? true)];
        }
        $market->forceFill(['settings' => $settings])->save();

        return response()->json(['data' => $this->present($market)]);
    }

    /** Start from a regional preset: price ending, announcement and perks in the market's language, duty handling. */
    public function applyPreset(Request $request, Market $market): JsonResponse
    {
        $data = $request->validate(['preset' => ['required', Rule::in(array_keys(\App\Markets\Presets::ALL))]]);
        $market->forceFill([
            'settings' => \App\Markets\Presets::settingsFor($data['preset'], (array) ($market->settings ?? [])),
            'rounding' => \App\Markets\Presets::ALL[$data['preset']]['rounding'],
        ])->save();
        // Announcement and perks in the market's language go straight onto its live store design
        $live = app(\App\Themes\ThemeService::class)->ensureLive(app(CurrentStore::class)->getOrFail());
        app(\App\Themes\MarketThemes::class)->setLive($live, $market, \App\Markets\Presets::themeTexts($data['preset'], $market->default_locale));

        return response()->json(['data' => $this->present($market)]);
    }

    /** Upcoming shopping events in the market's countries (or worldwide ones for the default site, id 0). */
    public function calendar(int $market): JsonResponse
    {
        $countries = $market ? (array) Market::findOrFail($market)->countries : [];

        return response()->json(['data' => \App\Markets\ShoppingCalendar::upcoming($countries)]);
    }

    public function store(Request $request): JsonResponse
    {
        $market = new Market(['position' => (int) Market::max('position') + 1]);
        $this->save($market, $request);

        return response()->json(['data' => $this->present($market)], 201);
    }

    public function update(Request $request, Market $market): JsonResponse
    {
        $this->save($market, $request);

        return response()->json(['data' => $this->present($market)]);
    }

    public function destroy(Market $market): JsonResponse
    {
        $market->delete();   // orders keep their currency and rate; their market_id becomes null

        return response()->json(['ok' => true]);
    }

    /** Availability and prices of one product in every market. */
    public function product(Product $product): JsonResponse
    {
        return response()->json(['data' => $this->productPayload($product)]);
    }

    /**
     * Save availability and fixed prices. Prices are decimal amounts in the market currency;
     * null (or empty) = use the converted store price.
     */
    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'markets' => ['present', 'array'],
            'markets.*.id' => ['required', 'integer'],
            'markets.*.sold' => ['required', 'boolean'],
            'markets.*.prices' => ['present', 'array'],
            'markets.*.prices.*.variant_id' => ['required', 'integer'],
            'markets.*.prices.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'markets.*.prices.*.compare_at_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);
        $markets = Market::whereIn('id', array_column($data['markets'], 'id'))->get()->keyBy('id');   // this store's only
        $variantIds = $product->variants()->pluck('id')->flip();

        DB::transaction(function () use ($data, $markets, $variantIds, $product) {
            foreach ($data['markets'] as $row) {
                $market = $markets->get($row['id']) ?? throw ValidationException::withMessages(['markets' => __('市场不存在')]);
                if ($row['sold']) {
                    MarketProductExclusion::where('market_id', $market->id)->where('product_id', $product->id)->delete();
                } else {
                    MarketProductExclusion::firstOrCreate(['market_id' => $market->id, 'product_id' => $product->id]);
                }
                foreach ($row['prices'] as $p) {
                    if (! $variantIds->has($p['variant_id'])) {
                        continue;   // not a variant of this product
                    }
                    $price = isset($p['price']) && $p['price'] !== '' ? Money::toMinor($p['price']) : null;
                    $compare = isset($p['compare_at_price']) && $p['compare_at_price'] !== '' ? Money::toMinor($p['compare_at_price']) : null;
                    if ($price === null && $compare === null) {
                        MarketPrice::where('market_id', $market->id)->where('variant_id', $p['variant_id'])->get()->each->delete();   // one by one: price history
                    } else {
                        MarketPrice::updateOrCreate(['market_id' => $market->id, 'variant_id' => $p['variant_id']], ['price' => $price, 'compare_at_price' => $compare]);
                    }
                }
            }
        });

        return response()->json(['data' => $this->productPayload($product)]);
    }

    private function save(Market $market, Request $request): void
    {
        $store = app(CurrentStore::class)->getOrFail();
        $languages = array_keys(Localization::languages());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            // URL suffix: /{language}-{handle}/ — lowercase letters only
            'handle' => ['required', 'string', 'regex:/^[a-z]{2,12}$/',
                Rule::unique('markets', 'handle')->where('store_id', $store->id)->ignore($market->id)],
            'countries' => ['required', 'array', 'min:1', 'max:60'],
            'countries.*' => ['distinct', Rule::in(array_keys(Countries::all()))],
            'currency' => ['required', Rule::in(config('lumee.currencies'))],
            'default_locale' => ['required', Rule::in($languages)],
            'locales' => ['present', 'array'],
            'locales.*' => [Rule::in($languages)],
            'price_adjustment' => ['required', 'numeric', 'min:-90', 'max:500'],
            'rounding' => ['required', Rule::in(Market::ROUNDING)],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'enabled' => ['required', 'boolean'],
        ], [
            'handle.regex' => __('网址后缀只能是 2–12 个小写英文字母'),
            'handle.unique' => __('已有市场使用这个网址后缀'),
        ]);

        // A country belongs to one market, so shoppers (and search engines) are never sent two ways
        $taken = Market::whereKeyNot($market->id ?? 0)->get(['name', 'countries'])
            ->flatMap(fn ($m) => array_fill_keys((array) $m->countries, $m->name));
        foreach ($data['countries'] as $c) {
            if ($taken->has($c)) {
                throw ValidationException::withMessages(['countries' => __(':country 已属于市场「:market」', ['country' => Countries::all()[$c] ?? $c, 'market' => $taken[$c]])]);
            }
        }

        $data['locales'] = array_values(array_unique([$data['default_locale'], ...$data['locales']]));
        $market->fill($data);
        $market->setRelation('store', $store);
        if ($market->enabled && $market->rate($store) === null) {
            throw ValidationException::withMessages(['exchange_rate' => __('还没有 :currency 的汇率：请在「多币种」里更新汇率，或填写自定义汇率', ['currency' => $market->currency])]);
        }
        $market->save();
    }

    private function present(Market $m): array
    {
        $store = app(CurrentStore::class)->getOrFail();
        $m->setRelation('store', $store);
        $daily = app(ExchangeRates::class)->rate($store->baseCurrency(), $m->currency);

        return [
            ...$m->only('id', 'name', 'handle', 'countries', 'currency', 'default_locale', 'locales', 'price_adjustment', 'rounding', 'exchange_rate', 'enabled', 'position'),
            'daily_rate' => $daily !== null ? round($daily, 6) : null,
            'rate' => ($r = $m->rate($store)) !== null ? round($r, 6) : null,
            'url' => $store->storefrontUrl().$m->prefix().'/',
            'excluded_products' => MarketProductExclusion::where('market_id', $m->id)->count(),
            'settings' => (function () use ($m) {
                $s = \App\Markets\MarketSettings::of($m);
                $s['duties']['threshold'] = Money::format($s['duties']['threshold']);
                $s['cod']['fee'] = Money::format($s['cod']['fee']);
                $s['cod']['max'] = Money::format($s['cod']['max']);
                $s['installments']['min'] = Money::format($s['installments']['min']);
                $s['installments']['available'] = in_array($m->currency, \App\Payments\Installments::CURRENCIES, true);

                return $s;
            })(),
            // Local payment methods for this currency and whether the store has them on
            'payments' => (function () use ($m, $store) {
                $on = \App\Payments\PaymentSettings::for($store)->all()['stripe']['methods'] ?? [];

                return collect(\App\Payments\PaymentSettings::STRIPE_METHODS)->filter(fn ($def) => in_array($m->currency, $def[1], true))
                    ->map(fn ($def, $key) => ['key' => $key, 'name' => $def[0], 'enabled' => in_array($key, $on, true)])->values();
            })(),
            'fixed_prices' => MarketPrice::where('market_id', $m->id)->count(),
        ];
    }

    private function productPayload(Product $product): array
    {
        $store = app(CurrentStore::class)->getOrFail();
        $variants = $product->variants()->get(['id', 'title', 'sku', 'price', 'compare_at_price']);
        $ctx = app(MarketContext::class);

        return Market::orderBy('position')->orderBy('id')->get()->map(function (Market $m) use ($store, $variants, $product, $ctx) {
            $m->setRelation('store', $store);
            $rate = $m->rate($store);
            $fixed = MarketPrice::where('market_id', $m->id)->whereIn('variant_id', $variants->pluck('id'))->get()->keyBy('variant_id');
            $fmt = fn (?int $v) => $v === null ? null : Money::format($v);

            return [
                'id' => $m->id, 'name' => $m->name, 'currency' => $m->currency, 'enabled' => $m->enabled, 'has_rate' => $rate !== null,
                'sold' => ! MarketProductExclusion::where('market_id', $m->id)->where('product_id', $product->id)->exists(),
                'variants' => $variants->map(function ($v) use ($m, $rate, $fixed, $ctx, $fmt) {
                    // What the shopper pays when no fixed price is set (store price converted for this market)
                    [$auto, $autoCompare] = $rate === null ? [null, null] : $ctx->within($m, fn () => [
                        $ctx->convertPrice((int) $v->price),
                        $v->compare_at_price ? $ctx->convertPrice((int) $v->compare_at_price) : null,
                    ]);
                    $f = $fixed->get($v->id);

                    return [
                        'id' => $v->id, 'title' => $v->title, 'sku' => $v->sku,
                        'base_price' => $fmt((int) $v->price),
                        'auto_price' => $fmt($auto), 'auto_compare_at_price' => $fmt($autoCompare),
                        'price' => $fmt($f?->price), 'compare_at_price' => $fmt($f?->compare_at_price),
                    ];
                })->values(),
            ];
        })->values()->all();
    }
}
