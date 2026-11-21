<?php

namespace App\LeadFinder;

use App\Models\Lead;

/**
 * Rule-based lead score (0–100), always available, explained in plain words:
 *   fit   (≤ 65) = how close the business is to our product (≤ 40) + what kind of buyer it is (≤ 25)
 *   reach (≤ 35) = how reachable they are (≤ 25) + how established they are (≤ 10)
 * When AI analysis runs, its fit judgement replaces the rule-based fit; reach stays rule-based.
 */
class LeadScorer
{
    public const SEGMENT_POINTS = ['wholesale' => 25, 'repair' => 18, 'ecommerce' => 16, 'retail' => 12, 'brand' => 14, 'other' => 0];

    public const SEGMENT_LABELS = ['wholesale' => '批发 / 分销商', 'repair' => '维修店', 'ecommerce' => '电商卖家', 'retail' => '零售门店', 'brand' => '品牌商', 'other' => '其他'];

    /** @return array{score: int, segment: string, reasons: string[], fit: int, reach: int} */
    public function score(Lead $lead, array $extraTerms = []): array
    {
        $profile = LeadProfiles::get($lead->search?->industry ?? 'custom');
        $terms = array_values(array_unique([...$profile['relevance'], ...array_map('mb_strtolower', $extraTerms)]));
        $name = mb_strtolower($lead->name.' '.$lead->category);
        $text = mb_strtolower(($lead->summary ?? '').' '.($lead->site_text ?? ''));
        $reasons = [];

        // ── Relevance ──
        $rel = 0;
        $nameHits = self::hits($name, $terms);
        $textHits = self::hits($text, $terms);
        if ($nameHits) {
            $rel += 22;
            $reasons[] = '名称/类别命中：'.implode('、', array_slice($nameHits, 0, 3));
        }
        if (preg_match('/mobile phone|mobile_phone|cell phone|electronics repair|telecommunication|phone/i', (string) $lead->category)) {
            $rel += 8;
        }
        if ($textHits) {
            $rel += min(20, 6 + 3 * count($textHits));
            $reasons[] = '官网提到：'.implode('、', array_slice($textHits, 0, 4));
        }
        $rel = min(40, $rel);
        if (! $rel) {
            $reasons[] = '没有看到和产品相关的内容';
        }

        // ── Segment ──
        $segment = $lead->ai_scored ? $lead->segment : $this->segment($name, $text, $lead->signals ?? []);
        $seg = self::SEGMENT_POINTS[$segment] ?? 0;
        if ($segment !== 'other') {
            $reasons[] = '客户类型：'.self::SEGMENT_LABELS[$segment];
        }

        // ── Reach ──
        $reach = 0;
        $emails = $lead->emails ?? [];
        $domain = $lead->domain;
        if ($emails) {
            $own = $domain && collect($emails)->contains(fn ($e) => str_ends_with($e, '@'.$domain));
            $reach += $own ? 13 : 10;
            $reasons[] = $own ? '有企业邮箱' : '有联系邮箱';
        }
        if ($lead->phone || $lead->phones) {
            $reach += 6;
        }
        if (! empty($lead->socials['whatsapp'])) {
            $reach += 3;
            $reasons[] = '有 WhatsApp';
        } elseif ($lead->socials) {
            $reach += 2;
        }
        if ($lead->website) {
            $reach += 3;
        }
        $reach = min(25, $reach);

        $rep = 0;
        if ($lead->reviews_count) {
            $rep = $lead->reviews_count >= 100 ? 10 : ($lead->reviews_count >= 20 ? 7 : 4);
            if ($lead->rating && $lead->rating < 3.5) {
                $rep -= 2;
            }
            if ($lead->reviews_count >= 100) {
                $reasons[] = "{$lead->reviews_count} 条评价，规模较大";
            }
        } elseif ($lead->website) {
            $rep = 3;
        }

        $fit = $rel + $seg;

        return ['score' => max(0, min(100, $fit + $reach + $rep)), 'segment' => $segment, 'reasons' => $reasons, 'fit' => $fit, 'reach' => $reach + $rep];
    }

    public function segment(string $name, string $text, array $signals): string
    {
        $all = $name.' '.$text;
        $count = fn (string $seg) => count(self::hits($all, LeadProfiles::SEGMENT_TERMS[$seg]));
        if (self::hits($name, LeadProfiles::SEGMENT_TERMS['wholesale']) || $count('wholesale') >= 2) {
            return 'wholesale';
        }
        if (self::hits($name, LeadProfiles::SEGMENT_TERMS['repair']) || $count('repair') >= 2) {
            return 'repair';
        }
        if (array_intersect($signals, ['shopify', 'woocommerce', 'magento', 'bigcommerce']) || $count('ecommerce') >= 2) {
            return 'ecommerce';
        }
        if ($count('wholesale') === 1) {
            return 'wholesale';
        }
        if ($count('repair') === 1) {
            return 'repair';
        }

        return $count('retail') || $name !== '' ? 'retail' : 'other';
    }

    /** Distinct terms found as whole words (works for multi-word and non-Latin terms). */
    public static function hits(string $haystack, array $terms): array
    {
        $found = [];
        foreach ($terms as $t) {
            if ($t === '' || isset($found[$t])) {
                continue;
            }
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($t, '/').'(?![\p{L}\p{N}])/iu', $haystack)) {
                $found[$t] = $t;
            }
        }

        return array_values($found);
    }
}
