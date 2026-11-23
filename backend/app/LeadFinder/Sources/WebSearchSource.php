<?php

namespace App\LeadFinder\Sources;

use App\LeadFinder\LeadProfiles;
use App\LeadFinder\SafeFetcher;
use App\LeadFinder\SiteEnricher;
use App\Research\SerpApi;
use Illuminate\Support\Str;

/**
 * Leads from Google web results (through SerpApi, the merchant's key — the same one the research
 * radar uses), one channel at a time:
 *
 *   web        company websites (marketplaces, directories and news sites are skipped)
 *   facebook   public Facebook pages        site:facebook.com
 *   instagram  public Instagram profiles    site:instagram.com
 *   linkedin   LinkedIn company pages       site:linkedin.com/company
 *   tiktok     TikTok accounts              site:tiktok.com/@
 *
 * Only what Google shows publicly is used: the result's title, link and snippet (a business often
 * puts its email, phone, WhatsApp and website in its bio, which shows up in the snippet). The social
 * networks themselves are never fetched or logged into; a company website found this way is read
 * later like any other lead's website.
 */
class WebSearchSource
{
    public const CHANNELS = ['web', 'facebook', 'instagram', 'linkedin', 'tiktok'];

    /** At most this many Google searches per lead search (each is billed by SerpApi). */
    public const MAX_QUERIES = 40;

    private const SITE = [
        'facebook' => 'site:facebook.com', 'instagram' => 'site:instagram.com', 'linkedin' => 'site:linkedin.com/company', 'tiktok' => 'site:tiktok.com/@',
    ];

    /** Web results that are not a company's own site. */
    private const NOT_A_COMPANY = '/(^|\.)(amazon|ebay|aliexpress|alibaba|temu|shein|wish|etsy|walmart|mercadolibre|mercadolivre|rakuten|shopee|lazada|tokopedia|flipkart|'
        .'yelp|yellowpages|paginasamarillas|tripadvisor|foursquare|trustpilot|glassdoor|indeed|crunchbase|zoominfo|dnb|kompass|europages|yell|'
        .'wikipedia|youtube|reddit|quora|pinterest|medium|blogspot|wordpress|twitter|x|threads|google|bing|apple|microsoft|'
        .'nytimes|bbc|cnn|forbes|bloomberg|reuters)\.[a-z.]+$/i';

    public function __construct(private SerpApi $api) {}

    /**
     * @param  string[]  $channels
     * @return array<int, array>  places in the shape LeadPipeline::upsert takes
     */
    public function search(array $channels, array $keywords, string $country, array $cities, int $limit, ?callable $progress = null): array
    {
        $c = LeadProfiles::countries()[$country] ?? ['en' => $country, 'lang' => 'en'];
        $channels = array_values(array_intersect(self::CHANNELS, $channels)) ?: ['web'];
        $out = [];
        $queries = 0;
        foreach ($channels as $channel) {
            foreach ($cities ?: [null] as $city) {
                foreach ($keywords as $kw) {
                    if (count($out) >= $limit || $queries >= self::MAX_QUERIES) {
                        break 3;
                    }
                    $place = $city ?: $c['en'];
                    $q = trim(($channel === 'web' ? '' : self::SITE[$channel].' ')."{$kw} {$place}");
                    $json = $this->api->google($q, $country, $c['lang']);
                    $queries++;
                    foreach ((array) ($json['organic_results'] ?? []) as $r) {
                        $lead = self::fromResult($channel, $r, $country, $city);
                        if ($lead && ! isset($out[$lead['source_id']])) {
                            $out[$lead['source_id']] = $lead;
                        }
                    }
                    $progress && $progress(count($out));
                }
            }
        }

        return array_slice(array_values($out), 0, $limit);
    }

    /** One Google result → a lead, or null when it isn't a company (a post, a marketplace listing, a directory…). */
    public static function fromResult(string $channel, array $r, string $country, ?string $city): ?array
    {
        $link = (string) ($r['link'] ?? '');
        $title = trim((string) ($r['title'] ?? ''));
        $snippet = trim(implode(' ', array_filter([(string) ($r['snippet'] ?? ''), (string) ($r['rich_snippet']['top']['extensions'][0] ?? '')])));
        $host = SafeFetcher::domain($link);
        if (! $host || $title === '') {
            return null;
        }
        $contacts = self::contacts($snippet);

        if ($channel === 'web') {
            if (SiteEnricher::socialKind($host) || preg_match(self::NOT_A_COMPANY, $host)) {
                return null;
            }
            $parts = parse_url($link);

            return self::lead('web', $host, self::cleanTitle($title, null), $country, $city, [
                ...$contacts,
                'website' => ($parts['scheme'] ?? 'https').'://'.$parts['host'],
                'summary' => $snippet,
            ]);
        }

        $profile = self::profile($channel, $link);
        if (! $profile) {
            return null;
        }
        [$url, $handle] = $profile;

        return self::lead($channel, $channel.':'.mb_strtolower($handle), self::cleanTitle($title, $handle), $country, $city, [
            ...$contacts,
            'socials' => [$channel => $url] + ($contacts['socials'] ?? []),
            'summary' => $snippet,
        ]);
    }

    /** A profile / page URL and its handle; null for posts, videos, groups, search pages… */
    public static function profile(string $channel, string $link): ?array
    {
        $p = parse_url($link);
        $path = trim((string) ($p['path'] ?? ''), '/');
        $seg = $path === '' ? [] : explode('/', $path);
        $first = $seg[0] ?? '';

        return match ($channel) {
            'instagram' => $first !== '' && preg_match('/^[A-Za-z0-9._]{2,30}$/', $first) && ! in_array($first, ['p', 'reel', 'reels', 'explore', 'stories', 'accounts', 'tv', 'popular'], true)
                ? ['https://www.instagram.com/'.$first.'/', $first] : null,
            'tiktok' => str_starts_with($first, '@') && strlen($first) > 2 ? ['https://www.tiktok.com/'.$first, ltrim($first, '@')] : null,
            'linkedin' => $first === 'company' && ! empty($seg[1]) ? ['https://www.linkedin.com/company/'.$seg[1].'/', $seg[1]] : null,
            'facebook' => match (true) {
                $first === 'p' && ! empty($seg[1]) => ['https://www.facebook.com/p/'.$seg[1].'/', $seg[1]],   // new-style page URL
                in_array($first, ['', 'p', 'people', 'profile.php', 'groups', 'events', 'watch', 'marketplace', 'photo', 'photo.php', 'story.php', 'permalink.php',
                    'share', 'hashtag', 'login', 'help', 'policies', 'reel', 'videos', 'search'], true) => null,
                count($seg) === 1 && (bool) preg_match('/^[A-Za-z0-9.\-]{3,80}$/', $first) => ['https://www.facebook.com/'.$first.'/', $first],
                default => null,   // a post, photo or video of a page
            },
            default => null,
        };
    }

    /** "Acme Cases (@acme.cases) • Instagram photos and videos" → "Acme Cases" */
    public static function cleanTitle(string $title, ?string $handle): string
    {
        $t = preg_replace('/\s*[|•·\-–—]\s*(Facebook|Instagram|LinkedIn|TikTok)\b.*$/iu', '', $title);
        $t = preg_replace('/\s*\((@[^)]+)\)\s*/u', ' ', $t);
        $t = preg_replace('/\s*(Instagram photos and videos|on TikTok|TikTok|- Home)\s*$/iu', '', $t);
        $t = trim(preg_replace('/\s{2,}/u', ' ', $t), " \t|-–—·•");
        if ($t === '' && $handle) {
            $t = $handle;
        }

        return Str::limit($t, 200, '');
    }

    /**
     * Contacts written in a snippet: emails, phone numbers, WhatsApp, a website, audience size.
     *
     * @return array{emails?: string[], phone?: string, socials?: array, website?: string, followers?: int}
     */
    public static function contacts(string $text): array
    {
        $out = [];
        if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $m)) {
            $out['emails'] = array_values(array_unique(array_map('strtolower', array_map(fn ($e) => rtrim($e, '.'), $m[0]))));
        }
        if (preg_match('/(?:wa\.me\/|whatsapp[:\s]*)(\+?\d[\d\s\-]{7,16}\d)/i', $text, $m)) {
            $out['socials']['whatsapp'] = 'https://wa.me/'.preg_replace('/\D/', '', $m[1]);
        }
        if (preg_match('/(\+\d{1,3}[\s\-.]?\(?\d{1,4}\)?(?:[\s\-.]?\d{2,4}){2,4})/', $text, $m)) {
            $out['phone'] = trim($m[1]);
        }
        // A website named in the bio (not the network itself, not an email domain)
        $clean = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', ' ', $text);
        if (preg_match_all('/\b((?:https?:\/\/)?(?:www\.)?[a-z0-9][a-z0-9\-]{1,62}(?:\.[a-z0-9\-]{2,63})*\.(?:com|net|org|shop|store|co|io|biz|info|[a-z]{2})(?:\.[a-z]{2})?)\b(?!@)/i', $clean, $m)) {
            foreach ($m[1] as $site) {
                $host = SafeFetcher::domain(str_contains($site, '://') ? $site : 'https://'.$site);
                if ($host && ! SiteEnricher::socialKind($host) && ! preg_match(self::NOT_A_COMPANY, $host) && ! preg_match('/^(linktr\.ee|bit\.ly|beacons\.ai|wa\.me)$/', $host)) {
                    $out['website'] = 'https://'.$host;
                    break;
                }
            }
        }
        if (preg_match('/([\d.,]+)\s*([KkMm]|mil|tys\.?)?\s*(followers|seguidores|abonnés|follower|likes|me gusta|curtidas)/u', $text, $m)) {
            $n = (float) str_replace(',', '.', preg_replace('/[.,](?=\d{3}\b)/', '', $m[1]));
            $mult = match (strtolower($m[2] ?? '')) { 'k', 'mil' => 1000, 'm' => 1000000, default => 1 };
            $out['followers'] = (int) round($n * $mult);
        }

        return $out;
    }

    private static function lead(string $channel, string $id, string $name, string $country, ?string $city, array $extra): array
    {
        return [
            'source' => 'web',
            'source_id' => Str::limit($id, 180, ''),
            'name' => $name,
            'category' => ['web' => null, 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok'][$channel],
            'country' => $country,
            'city' => $city,
            'address' => null, 'lat' => null, 'lng' => null,
            'phone' => $extra['phone'] ?? null,
            'website' => $extra['website'] ?? null,
            'emails' => $extra['emails'] ?? [],
            'socials' => $extra['socials'] ?? [],
            'followers' => $extra['followers'] ?? null,
            'summary' => isset($extra['summary']) && $extra['summary'] !== '' ? Str::limit($extra['summary'], 480, '') : null,
            'rating' => null, 'reviews_count' => null,
        ];
    }
}
