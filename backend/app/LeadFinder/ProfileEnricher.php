<?php

namespace App\LeadFinder;

use App\Models\Lead;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fills in what a lead's public profile or domain says, through official APIs only (keys in the lead
 * finder settings; each is optional):
 *
 *  - Instagram Business Discovery (Meta Graph API, with the merchant's own Instagram business account
 *    and token): bio, website, follower count of a business / creator account. Personal accounts are
 *    not visible to this API, by design.
 *  - Hunter.io domain search: published work emails at a company's domain (with names and job titles
 *    when known), for leads whose website didn't list an email.
 */
class ProfileEnricher
{
    public const GRAPH = 'https://graph.facebook.com/v19.0';

    public const HUNTER = 'https://api.hunter.io/v2/domain-search';

    /** Hunter's confidence below this is a guess, not a published address. */
    private const MIN_CONFIDENCE = 70;

    public static function instagramReady(Store $store): bool
    {
        return LeadSettings::metaToken($store) !== null && LeadSettings::igUserId($store) !== null;
    }

    public static function hunterReady(Store $store): bool
    {
        return LeadSettings::hunterKey($store) !== null;
    }

    /**
     * @return array{name: ?string, biography: ?string, website: ?string, followers: ?int, media: ?int}|null  null = not a business account
     */
    public function instagram(Store $store, string $username): ?array
    {
        $username = ltrim(trim($username), '@');
        if (! preg_match('/^[A-Za-z0-9._]{1,30}$/', $username)) {
            return null;
        }
        $fields = "business_discovery.username({$username}){name,username,biography,website,followers_count,media_count}";
        $r = Http::timeout(15)->get(self::GRAPH.'/'.LeadSettings::igUserId($store), ['fields' => $fields, 'access_token' => LeadSettings::metaToken($store)]);
        $err = $r->json('error');
        if ($err) {
            // 110 / 100 with "Invalid user id": the account isn't a business or creator account (or doesn't exist)
            if (in_array((int) ($err['code'] ?? 0), [100, 110], true) && ! str_contains((string) ($err['message'] ?? ''), 'access token')) {
                return null;
            }
            throw new RuntimeException('Instagram 接口：'.mb_substr((string) ($err['message'] ?? 'HTTP '.$r->status()), 0, 160));
        }
        $d = (array) $r->json('business_discovery');

        return $d ? [
            'name' => $d['name'] ?? null, 'biography' => $d['biography'] ?? null, 'website' => $d['website'] ?? null,
            'followers' => isset($d['followers_count']) ? (int) $d['followers_count'] : null, 'media' => isset($d['media_count']) ? (int) $d['media_count'] : null,
        ] : null;
    }

    /** @return array<int, array{email: string, name: ?string, position: ?string, type: ?string}> */
    public function hunter(Store $store, string $domain): array
    {
        $r = Http::timeout(15)->get(self::HUNTER, ['domain' => $domain, 'limit' => 10, 'api_key' => LeadSettings::hunterKey($store)]);
        if (! $r->successful()) {
            $msg = $r->json('errors.0.details') ?? 'HTTP '.$r->status();
            throw new RuntimeException(match ($r->status()) {
                401 => 'Hunter.io Key 无效',
                429 => 'Hunter.io 额度已用完',
                default => 'Hunter.io：'.mb_substr((string) $msg, 0, 160),
            });
        }
        $out = [];
        foreach ((array) $r->json('data.emails') as $e) {
            if (empty($e['value']) || (int) ($e['confidence'] ?? 0) < self::MIN_CONFIDENCE) {
                continue;
            }
            $out[] = [
                'email' => strtolower($e['value']),
                'name' => trim(($e['first_name'] ?? '').' '.($e['last_name'] ?? '')) ?: null,
                'position' => $e['position'] ?? null,
                'type' => $e['type'] ?? null,   // personal | generic
            ];
        }
        // Role inboxes (sales@, info@) first: that's where a supplier's first email belongs
        usort($out, fn ($a, $b) => (int) ($a['type'] !== 'generic') <=> (int) ($b['type'] !== 'generic'));

        return $out;
    }

    /** Instagram username of a lead, from its profile link. */
    public static function instagramHandle(Lead $lead): ?string
    {
        $url = $lead->socials['instagram'] ?? null;

        return $url && preg_match('#instagram\.com/([A-Za-z0-9._]{1,30})/?#i', $url, $m) && ! in_array($m[1], ['p', 'reel', 'explore'], true) ? $m[1] : null;
    }
}
