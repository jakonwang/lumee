<?php

namespace App\Analytics;

use App\Models\Order;
use App\Models\Store;
use App\Tenancy\CurrentStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * First-party storefront analytics: sessions (visits) and the steps of the purchase funnel.
 * Two cookies — a visitor id (1 year) and a session id (30 minutes, sliding). No IP address or other
 * personal data is stored; bots, link previews, prefetches and theme-editor previews are ignored.
 * Request-scoped; every method fails silently so analytics can never break the storefront.
 */
class Visits
{
    public const SESSION_COOKIE = 'lm_s';

    public const VISITOR_COOKIE = 'lm_v';

    public const SESSION_MINUTES = 30;

    private const BOTS = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|curl|wget|python|java\/|go-http|headless|lighthouse|pingdom|uptime|monitor|scrapy|httpclient|okhttp|axios/i';

    private const SEARCH = ['google.', 'bing.com', 'baidu.com', 'yahoo.', 'duckduckgo.com', 'yandex.', 'naver.com', 'sogou.com', 'so.com', 'ecosia.org', 'seznam.cz'];

    private const SOCIAL = ['facebook.com', 'fb.com', 'instagram.com', 't.co', 'twitter.com', 'x.com', 'pinterest.', 'tiktok.com', 'youtube.com', 'youtu.be',
        'linkedin.com', 'lnkd.in', 'reddit.com', 'weibo.com', 'xiaohongshu.com', 'douyin.com', 'snapchat.com', 'threads.net', 'vk.com', 'line.me', 'whatsapp.com'];

    private const MAIL = ['mail.google.com', 'outlook.', 'mail.yahoo.', 'mail.qq.com', 'mail.163.com', 'webmail.'];

    private const PAID_MEDIUMS = ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-social', 'paid_social', 'paidsocial', 'cpm', 'display', 'cpv', 'ads', 'ad'];

    /** Headers set by a CDN / proxy with the visitor's country */
    private const GEO_HEADERS = ['CF-IPCountry', 'CloudFront-Viewer-Country', 'X-Country-Code', 'X-Geo-Country', 'Fastly-Geo-Country'];

    private ?object $session = null;

    private bool $resolved = false;

    private array $cookies = [];

    public function __construct(private CurrentStore $current, private Request $unused) {}

    private function req(): Request
    {
        return request();
    }

    /** Called by the middleware for every storefront page view. */
    public function pageview(Request $request): void
    {
        if (! $this->trackable($request) || $request->method() !== 'GET') {
            return;
        }
        $this->guard(function () {
            $s = $this->session(create: true);
            if ($s) {
                DB::table('analytics_sessions')->where('id', $s->id)->update(['pageviews' => DB::raw('pageviews + 1'), 'last_seen_at' => now()]);
            }
        });
    }

    public function productView(int $productId): void
    {
        $this->guard(function () use ($productId) {
            if (! $this->trackable($this->req()) || ! ($s = $this->session(create: true))) {
                return;
            }
            $this->flag($s, 'viewed_product');
            $this->bumpProduct($productId, 'views');
        });
    }

    public function addToCart(?int $productId): void
    {
        $this->guard(function () use ($productId) {
            if (! $this->trackable($this->req()) || ! ($s = $this->session(create: true))) {
                return;
            }
            $this->flag($s, 'added_to_cart');
            $this->flag($s, 'viewed_product');
            if ($productId) {
                $this->bumpProduct($productId, 'carts');
            }
        });
    }

    public function checkout(): void
    {
        $this->guard(function () {
            if (! $this->trackable($this->req()) || ! ($s = $this->session(create: true))) {
                return;
            }
            $this->flag($s, 'reached_checkout');
        });
    }

    /** The order placed in this visit (it counts as a purchase once it's paid). */
    public function order(Order $order): void
    {
        $this->guard(function () use ($order) {
            $s = $this->session(create: false);
            if ($s && ! $s->order_id) {
                DB::table('analytics_sessions')->where('id', $s->id)->update(['order_id' => $order->id, 'reached_checkout' => true, 'last_seen_at' => now()]);
                $s->order_id = $order->id;
            }
        });
    }

    /** Cookies to attach to the response (set by the middleware). */
    public function attachCookies(Response $response): void
    {
        foreach ($this->cookies as $c) {
            $response->headers->setCookie($c);
        }
    }

    public function trackable(Request $request): bool
    {
        if (! $this->current->check() || $request->attributes->get('theme_preview')) {
            return false;
        }
        $ua = (string) $request->userAgent();
        if ($ua === '' || preg_match(self::BOTS, $ua)) {
            return false;
        }
        $purpose = strtolower((string) ($request->header('Sec-Purpose') ?? $request->header('Purpose') ?? ''));

        return ! str_contains($purpose, 'prefetch') && ! str_contains($purpose, 'preview');
    }

    private function session(bool $create): ?object
    {
        if ($this->resolved && ($this->session || ! $create)) {
            return $this->session;
        }
        $this->resolved = true;
        $store = $this->current->getOrFail();
        $token = (string) $this->req()->cookie(self::SESSION_COOKIE);
        if (Str::isUuid($token)) {
            $row = DB::table('analytics_sessions')->where('token', $token)->where('store_id', $store->id)
                ->where('last_seen_at', '>=', now()->subMinutes(self::SESSION_MINUTES))->first();
            if ($row) {
                $this->session = $row;
                $this->queueCookies($row->token, $row->visitor);

                return $row;
            }
        }
        if (! $create) {
            return null;
        }

        $visitor = (string) $this->req()->cookie(self::VISITOR_COOKIE);
        $returning = Str::isUuid($visitor);
        $visitor = $returning ? $visitor : ($this->newVisitor ?? (string) Str::uuid());   // the id A/B tests already used on this page
        $row = [
            'store_id' => $store->id, 'token' => (string) Str::uuid(), 'visitor' => $visitor,
            'returning' => $returning && DB::table('analytics_sessions')->where('store_id', $store->id)->where('visitor', $visitor)->exists(),
            'started_at' => now(), 'last_seen_at' => now(), 'pageviews' => 0,
            'landing_path' => Str::limit('/'.ltrim($this->req()->path(), '/'), 250, ''),
            ...$this->attribution($store),
            'country' => $this->country(),
            ...$this->device((string) $this->req()->userAgent()),
            // Which market site, and (during a design A/B test there) which version this visitor sees
            'market_id' => app(\App\Markets\MarketContext::class)->market()?->id,
            ...app(\App\Themes\DesignExperiments::class)->assignment($store->id, $visitor),
        ];
        $row['id'] = DB::table('analytics_sessions')->insertGetId($row);
        $row['order_id'] = null;
        $row['viewed_product'] = $row['added_to_cart'] = $row['reached_checkout'] = false;
        $this->session = (object) $row;
        $this->queueCookies($row['token'], $visitor);

        return $this->session;
    }

    /** Where the visit came from: UTM tags first, then ad click ids, then the referrer. */
    private function attribution(Store $store): array
    {
        $q = fn (string $k) => ($v = trim((string) $this->req()->query($k))) !== '' ? Str::limit(strtolower($v), 95, '') : null;
        $utm = ['utm_source' => $q('utm_source'), 'utm_medium' => $q('utm_medium'), 'utm_campaign' => $q('utm_campaign')];
        $ref = strtolower((string) parse_url((string) $this->req()->headers->get('referer'), PHP_URL_HOST));
        $ref = preg_replace('/^(www\.|m\.|l\.|lm\.)/', '', $ref);
        if ($ref !== '' && $ref === preg_replace('/^www\./', '', strtolower($this->req()->getHost()))) {
            $ref = '';   // internal navigation after the session expired
        }
        $has = fn (array $list) => $ref !== '' && collect($list)->contains(fn ($d) => str_starts_with($ref, $d) || str_contains($ref, '.'.$d) || str_contains($ref, $d));

        $medium = $utm['utm_medium'] ?? '';
        $source = match (true) {
            in_array($medium, self::PAID_MEDIUMS, true) || $this->req()->query('gclid') || $this->req()->query('msclkid') || $this->req()->query('ttclid') => 'paid',
            $medium === 'email' || $utm['utm_source'] === 'newsletter' || $has(self::MAIL) => 'email',
            $medium === 'social' || $has(self::SOCIAL) || $this->req()->query('fbclid') => 'social',
            $medium === 'organic' || $has(self::SEARCH) => 'search',
            $ref !== '' || $medium === 'referral' => 'referral',
            default => 'direct',
        };

        return [...$utm, 'source' => $source, 'referrer_host' => $ref !== '' ? Str::limit($ref, 115, '') : null];
    }

    /** Country from a CDN header; without one, the region in the browser language (en-US → US) as an estimate. */
    private function country(): ?string
    {
        foreach (self::GEO_HEADERS as $h) {
            $v = strtoupper(trim((string) $this->req()->header($h)));
            if (preg_match('/^[A-Z]{2}$/', $v) && ! in_array($v, ['XX', 'T1', 'ZZ'], true)) {
                return $v;
            }
        }
        if (preg_match('/^[a-z]{2,3}[-_]([A-Za-z]{2})\b/', (string) $this->req()->header('Accept-Language'), $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    private function device(string $ua): array
    {
        $device = match (true) {
            (bool) preg_match('/ipad|tablet|kindle|silk|playbook|(android(?!.*mobile))/i', $ua) => 'tablet',
            (bool) preg_match('/mobi|iphone|ipod|android|blackberry|opera mini|iemobile/i', $ua) => 'mobile',
            default => 'desktop',
        };
        $browser = match (true) {
            (bool) preg_match('/micromessenger/i', $ua) => 'WeChat',
            (bool) preg_match('/edg\//i', $ua) => 'Edge',
            (bool) preg_match('/opr\/|opera/i', $ua) => 'Opera',
            (bool) preg_match('/samsungbrowser/i', $ua) => 'Samsung',
            (bool) preg_match('/firefox|fxios/i', $ua) => 'Firefox',
            (bool) preg_match('/chrome|crios/i', $ua) => 'Chrome',
            (bool) preg_match('/safari/i', $ua) => 'Safari',
            default => 'Other',
        };

        return ['device' => $device, 'browser' => $browser];
    }

    private function flag(object $s, string $column): void
    {
        if (! $s->{$column}) {
            DB::table('analytics_sessions')->where('id', $s->id)->update([$column => true, 'last_seen_at' => now()]);
            $s->{$column} = true;
        }
    }

    private function bumpProduct(int $productId, string $column): void
    {
        $store = $this->current->getOrFail();
        $date = CarbonImmutable::now($store->timezone ?: 'UTC')->toDateString();
        DB::table('analytics_product_stats')->upsert(
            [['store_id' => $store->id, 'product_id' => $productId, 'date' => $date, 'views' => $column === 'views' ? 1 : 0, 'carts' => $column === 'carts' ? 1 : 0]],
            ['store_id', 'product_id', 'date'],
            [$column => DB::raw("analytics_product_stats.{$column} + 1")],
        );
        // The same per market (0 = default site), for test-and-learn by country
        DB::table('product_market_stats')->upsert(
            [['store_id' => $store->id, 'product_id' => $productId, 'market_id' => (int) app(\App\Markets\MarketContext::class)->market()?->id,
                'date' => $date, 'views' => $column === 'views' ? 1 : 0, 'carts' => $column === 'carts' ? 1 : 0]],
            ['store_id', 'product_id', 'market_id', 'date'],
            [$column => DB::raw("product_market_stats.{$column} + 1")],
        );
    }

    /** A storefront search and how many products it found (no-result searches show what to stock). */
    public function search(string $term, int $results): void
    {
        $this->guard(function () use ($term, $results) {
            $term = Str::limit(trim(preg_replace('/\s+/u', ' ', mb_strtolower($term))), 100, '');
            if ($term === '' || ! $this->trackable($this->req())) {
                return;
            }
            $store = $this->current->getOrFail();
            DB::table('search_terms')->upsert(
                [['store_id' => $store->id, 'market_id' => (int) app(\App\Markets\MarketContext::class)->market()?->id,
                    'date' => CarbonImmutable::now($store->timezone ?: 'UTC')->toDateString(), 'term' => $term,
                    'searches' => 1, 'zero_results' => $results === 0 ? 1 : 0, 'results' => $results]],
                ['store_id', 'market_id', 'date', 'term'],
                ['searches' => DB::raw('search_terms.searches + 1'), 'zero_results' => DB::raw('search_terms.zero_results + '.($results === 0 ? 1 : 0)), 'results' => $results],
            );
        });
    }

    /** Visitor id made up for a first visit before its session exists (the page renders before it's counted). */
    private ?string $newVisitor = null;

    /**
     * This browser's visitor id (A/B test arms). On a first visit it is made up now and the session counted
     * after the page uses the same one, so the version shown and the version credited always match.
     */
    public function visitorId(): ?string
    {
        $v = $this->session?->visitor ?? (string) $this->req()->cookie(self::VISITOR_COOKIE);
        if (! Str::isUuid((string) $v) && $this->current->check()) {
            $v = $this->newVisitor ??= (string) Str::uuid();
        }

        return Str::isUuid((string) $v) ? (string) $v : null;
    }

    private function queueCookies(string $session, string $visitor): void
    {
        $secure = $this->req()->isSecure();
        $this->cookies = [
            Cookie::create(self::SESSION_COOKIE, $session, now()->addMinutes(self::SESSION_MINUTES), '/', null, $secure, true, false, 'Lax'),
            Cookie::create(self::VISITOR_COOKIE, $visitor, now()->addYear(), '/', null, $secure, true, false, 'Lax'),
        ];
    }

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
