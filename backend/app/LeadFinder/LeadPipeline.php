<?php

namespace App\LeadFinder;

use App\LeadFinder\Sources\GooglePlacesSource;
use App\LeadFinder\Sources\OsmSource;
use App\Models\Lead;
use App\Models\LeadSearch;
use App\Models\Store;
use RuntimeException;

/** The steps of a lead search: find places → save (deduplicated) → read their sites → score. */
class LeadPipeline
{
    public function __construct(private SiteEnricher $enricher, private LeadScorer $scorer, private LeadAi $ai) {}

    public function find(Store $store, LeadSearch $search, int $limit, ?callable $progress = null): array
    {
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
        ], fn ($v) => $v !== null && $v !== '');
        $emails = $place['emails'] ?? [];
        $socials = $place['socials'] ?? [];

        if ($isNew) {
            $lead = Lead::create([...$fields, 'source' => $place['source'], 'source_id' => $place['source_id'], 'lead_search_id' => $search?->id,
                'emails' => $emails, 'socials' => $socials, 'enrich_status' => $domain ? 'pending' : 'skipped']);
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
        if (! $lead->website) {
            $lead->update(['enrich_status' => 'skipped']);

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
        $lead->save();
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
