<?php

namespace App\LeadFinder;

use App\LeadFinder\Sources\GooglePlacesSource;
use App\LeadFinder\Sources\OsmSource;
use App\LeadFinder\Sources\WebSearchSource;
use App\Research\SerpApi;
use App\Models\Lead;
use App\Models\LeadSearch;
use App\Models\Store;
use RuntimeException;

/** The steps of a lead search: find places → save (deduplicated) → read their sites → score. */
class LeadPipeline
{
    public function __construct(private SiteEnricher $enricher, private LeadScorer $scorer, private LeadAi $ai, private EmailChecker $emails, private ProfileEnricher $profiles) {}

    public function find(Store $store, LeadSearch $search, int $limit, ?callable $progress = null): array
    {
        if ($search->source === 'web') {
            $api = SerpApi::for($store) ?? throw new RuntimeException('还没有配置 SerpApi Key（在「获客设置」里填写）');

            return (new WebSearchSource($api))->search($search->channels ?? ['web'], $search->keywords, $search->country, $search->cities ?? [], $limit, $progress);
        }
        if ($search->source === 'google') {
            $key = LeadSettings::googleKey($store) ?? throw new RuntimeException('还没有配置 Google 地图 API Key');

            return (new GooglePlacesSource($key))->search($search->industry, $search->keywords, $search->country, $search->cities ?? [], $limit, $progress);
        }

        return app(OsmSource::class)->search($search->industry, $search->keywords, $search->country, $search->cities ?? [], $limit, $progress);
    }

    /** Insert or refresh a place. Same company found twice (same listing, website or name+city) stays one lead. */
    public function upsert(array $place, ?LeadSearch $search): array
    {
        $domain = SafeFetcher::domain($place['website'] ?? null);
        if ($domain && SiteEnricher::socialKind($domain)) {
            $place['socials'] = [...($place['socials'] ?? []), SiteEnricher::socialKind($domain) => $place['website']];
            $place['website'] = null;
            $domain = null;
        }
        $lead = Lead::where('source', $place['source'])->where('source_id', $place['source_id'])->first()
            ?? ($domain ? Lead::where('domain', $domain)->first() : null)
            ?? Lead::where('name', $place['name'])->where('city', $place['city'] ?? null)->first();
        $isNew = ! $lead;

        $fields = array_filter([
            'name' => $place['name'], 'category' => $place['category'] ?: null, 'country' => $place['country'] ?? null, 'city' => $place['city'] ?? null,
            'address' => $place['address'] ?? null, 'lat' => $place['lat'] ?? null, 'lng' => $place['lng'] ?? null, 'phone' => $place['phone'] ?? null,
            'website' => $place['website'] ?? null, 'domain' => $domain, 'rating' => $place['rating'] ?? null, 'reviews_count' => $place['reviews_count'] ?? null,
            'followers' => $place['followers'] ?? null, 'summary' => $place['summary'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        $emails = $place['emails'] ?? [];
        $socials = $place['socials'] ?? [];

        if ($isNew) {
            $lead = Lead::create([...$fields, 'source' => $place['source'], 'source_id' => $place['source_id'], 'lead_search_id' => $search?->id,
                'emails' => $emails, 'socials' => $socials, 'enrich_status' => $domain || isset($socials['instagram']) ? 'pending' : 'skipped']);
            $lead->activities()->create(['type' => 'created', 'body' => $search ? '来自搜索：'.$search->name : '手动添加']);
        } else {
            // Keep what the merchant already did with it (status, notes); fill gaps from the new listing
            $lead->fill(array_filter($fields, fn ($v, $k) => blank($lead->{$k}), ARRAY_FILTER_USE_BOTH));
            $lead->emails = array_values(array_unique([...($lead->emails ?? []), ...$emails]));
            $lead->socials = ($lead->socials ?? []) + $socials;
            if ($domain && $lead->enrich_status === 'skipped') {
                $lead->enrich_status = 'pending';
            }
            $lead->save();
        }

        return [$lead, $isNew];
    }

    public function enrich(Lead $lead): void
    {
        $store = app(\App\Tenancy\CurrentStore::class)->getOrFail();
        $notes = [];
        // Found on Instagram without a website: the business profile usually links one
        if (! $lead->website && ($handle = ProfileEnricher::instagramHandle($lead)) && ProfileEnricher::instagramReady($store)) {
            try {
                if ($ig = $this->profiles->instagram($store, $handle)) {
                    $site = $ig['website'] && ($host = SafeFetcher::domain($ig['website'])) && ! SiteEnricher::socialKind($host) ? $ig['website'] : null;
                    $bio = WebSearchSource::contacts((string) $ig['biography']);
                    $lead->fill(array_filter([
                        'website' => $site, 'domain' => $site ? SafeFetcher::domain($site) : null,
                        'followers' => $ig['followers'], 'summary' => $lead->summary ?: ($ig['biography'] ? mb_substr($ig['biography'], 0, 500) : null),
                        'phone' => $lead->phone ?: ($bio['phone'] ?? null),
                    ], fn ($v) => $v !== null));
                    $lead->emails = array_values(array_unique([...($lead->emails ?? []), ...($bio['emails'] ?? [])]));
                    $lead->socials = ($lead->socials ?? []) + ($bio['socials'] ?? []);
                } else {
                    $notes[] = 'Instagram 不是商业账号，读不到资料';
                }
            } catch (\Throwable $e) {
                $notes[] = $e->getMessage();
            }
        }
        if (! $lead->website) {
            $lead->enrich_status = 'skipped';
            if ($notes) {
                $lead->enrich_error = mb_substr(implode('；', $notes), 0, 200);
            }
            $this->checkEmails($lead);
            $lead->save();

            return;
        }
        try {
            $site = $this->enricher->enrich($lead->website);
            $lead->fill([
                'emails' => array_values(array_unique([...($lead->emails ?? []), ...$site['emails']])),
                'phones' => array_values(array_diff($site['phones'], [preg_replace('/[^\d+]/', '', (string) $lead->phone)])),
                'socials' => ($lead->socials ?? []) + $site['socials'],
                'summary' => $site['description'] ? mb_substr($site['description'], 0, 500) : ($lead->ai_scored ? $lead->summary : ($site['title'] ? mb_substr($site['title'], 0, 300) : null)),
                'site_text' => $site['text'] ?: null,
                'signals' => $site['signals'],
                'enrich_status' => 'done', 'enrich_error' => null, 'enriched_at' => now(),
            ]);
            if ($lead->name === $lead->domain && ($brand = self::brandName($site['site_name'] ?? null, $site['title']))) {
                $lead->name = $brand;   // imported by URL: show the company's name, not its domain
            }
            if (! $lead->phone && $site['phones']) {
                $lead->phone = $site['phones'][0];
            }
        } catch (\Throwable $e) {
            $lead->fill(['enrich_status' => 'failed', 'enrich_error' => mb_substr($e->getMessage(), 0, 200), 'enriched_at' => now()]);
        }
        $this->hunter($store, $lead, $notes);
        if ($notes && $lead->enrich_status !== 'failed') {
            $lead->enrich_error = mb_substr(implode('；', $notes), 0, 200);
        }
        $this->checkEmails($lead);
        $lead->save();
    }

    /** No work email published on the site: Hunter.io lists the ones that appear elsewhere on the web, with names and job titles. */
    private function hunter(Store $store, Lead $lead, array &$notes): void
    {
        $own = $lead->domain && collect($lead->emails ?? [])->contains(fn ($e) => str_ends_with($e, '@'.$lead->domain));
        if (! $lead->domain || $own || ! ProfileEnricher::hunterReady($store)) {
            return;
        }
        try {
            $found = $this->profiles->hunter($store, $lead->domain);
            $lead->emails = array_values(array_unique([...array_column($found, 'email'), ...($lead->emails ?? [])]));
            $people = array_values(array_filter($found, fn ($f) => $f['name']));
            if ($people) {
                $lead->contacts = array_slice($people, 0, 5);
            }
        } catch (\Throwable $e) {
            $notes[] = $e->getMessage();
        }
    }

    /** Mark each email as deliverable / role / personal / free; propose info@domain when none was published. */
    public function checkEmails(Lead $lead): void
    {
        $old = $lead->email_checks ?? [];
        $emails = array_values(array_filter($lead->emails ?? [], fn ($e) => ! ($old[$e]['guessed'] ?? false)));   // re-derive guesses
        $checks = [];
        foreach ($emails as $e) {
            $checks[$e] = $this->emails->check($e);
        }
        // Real, deliverable addresses first
        usort($emails, fn ($a, $b) => (int) $checks[$b]['mx'] <=> (int) $checks[$a]['mx']);
        if (! $emails && ($g = $this->emails->guess($lead->domain))) {
            $emails = [$g];
            $checks[$g] = $this->emails->check($g, true);
        }
        $lead->emails = $emails;
        $lead->email_checks = $checks ?: null;
    }

    /** "GadgetFix | Cell Phone Parts & Tools" → "GadgetFix" */
    public static function brandName(?string $siteName, ?string $title): ?string
    {
        if ($siteName && mb_strlen($siteName) <= 60) {
            return $siteName;
        }
        if (! $title) {
            return null;
        }
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s[|\-–—:·]\s/u', $title)),
            fn ($p) => $p !== '' && ! preg_match('/^(home|homepage|home page|welcome|inicio|startseite|accueil|página inicial)$/iu', $p)));
        usort($parts, fn ($a, $b) => mb_strlen($a) <=> mb_strlen($b));
        $name = $parts[0] ?? null;

        return $name && mb_strlen($name) >= 2 && mb_strlen($name) <= 60 ? $name : null;
    }

    public function score(Lead $lead, array $extraTerms = []): void
    {
        $r = $this->scorer->score($lead, $extraTerms);
        $lead->fill(['score' => $r['score'], 'segment' => $r['segment'], 'score_reasons' => $r['reasons']])->save();
    }

    /** AI fit judgement replaces the rule-based fit; reachability stays rule-based. */
    public function analyze(Store $store, Lead $lead, array $extraTerms = []): void
    {
        $ai = $this->ai->analyze($store, $lead);
        $rule = $this->scorer->score($lead, $extraTerms);
        $reach = array_values(array_filter($rule['reasons'], fn ($r) => preg_match('/邮箱|WhatsApp|评价/u', $r)));
        $lead->fill([
            'ai_scored' => true,
            'segment' => $ai['segment'],
            'summary' => $ai['summary'] ?: $lead->summary,
            'score' => max(0, min(100, (int) round($ai['fit'] * 0.65) + $rule['reach'])),
            'score_reasons' => array_slice([...$ai['reasons'], ...$reach], 0, 6),
        ])->save();
    }
}
