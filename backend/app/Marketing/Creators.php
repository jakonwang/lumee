<?php

namespace App\Marketing;

use App\LeadFinder\LeadAi;
use App\LeadFinder\LeadProfiles;
use App\LeadFinder\LeadSettings;
use App\LeadFinder\ProfileEnricher;
use App\LeadFinder\Sources\WebSearchSource;
use App\Markets\MarketFit;
use App\Models\Affiliate;
use App\Models\Creator;
use App\Models\Store;
use App\Research\SerpApi;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creators (达人合作): find Instagram / TikTok / YouTube accounts in a niche and country, score them for
 * a collaboration, write the first message in their language, and turn the ones who agree into
 * affiliates with their own code (commission on the sales they bring, see App\Marketing\Affiliates).
 *
 * Found through Google results (SerpApi, the same key as the research radar and lead finder); Instagram
 * business / creator profiles are filled in with Meta's Business Discovery API when it is set up.
 * The networks themselves are never logged into or scraped.
 */
class Creators
{
    /** Google searches per creator search (platforms × keywords). */
    public const MAX_QUERIES = 9;

    public function __construct(private ProfileEnricher $profiles) {}

    /**
     * @param  string[]  $keywords  niche phrases, e.g. "phone case review", "unboxing gadgets"
     * @param  string[]  $platforms  instagram | tiktok | youtube
     * @return array{found: int, new: int}
     */
    public function search(Store $store, array $keywords, string $country, array $platforms): array
    {
        $api = SerpApi::for($store) ?? throw new RuntimeException('还没有配置 SerpApi Key（在「获客雷达 › 设置」里填写）');
        $c = LeadProfiles::countries()[$country] ?? ['en' => $country, 'lang' => 'en'];
        $platforms = array_values(array_intersect(WebSearchSource::CREATOR_CHANNELS, $platforms));
        $found = [];
        $queries = 0;
        foreach ($platforms as $platform) {
            foreach ($keywords as $kw) {
                if ($queries++ >= self::MAX_QUERIES) {
                    break 2;
                }
                $json = $api->google(WebSearchSource::siteFilter($platform).' '.$kw.' '.$c['en'], $country, $c['lang']);
                foreach ((array) ($json['organic_results'] ?? []) as $r) {
                    $hit = WebSearchSource::fromResult($platform, $r, $country, null);
                    if ($hit) {
                        $found[$hit['source_id']] = [$platform, $kw, $hit];
                    }
                }
            }
        }
        $new = 0;
        foreach ($found as [$platform, $kw, $hit]) {
            $new += $this->upsert($store, $platform, $kw, $hit) ? 1 : 0;
        }
        // Sequences set to take new creators automatically
        $handles = collect($found)->map(fn ($f) => [$f[0], Str::after($f[2]['source_id'], $f[0].':')]);
        $people = Creator::where(fn ($q) => $handles->each(fn ($h) => $q->orWhere(fn ($w) => $w->where('platform', $h[0])->where('handle', $h[1]))))->get();
        if ($people->isNotEmpty()) {
            rescue(fn () => app(\App\Outreach\Sequencer::class)->autoEnroll($store, $people, 'creators'));
        }

        return ['found' => count($found), 'new' => $new];
    }

    /** @return bool true when it's a new creator */
    private function upsert(Store $store, string $platform, string $niche, array $hit): bool
    {
        $handle = Str::after($hit['source_id'], $platform.':');
        $creator = Creator::firstOrNew(['platform' => $platform, 'handle' => $handle]);
        $isNew = ! $creator->exists;
        $creator->fill([
            'url' => $hit['socials'][$platform] ?? $hit['website'] ?? '',
            'name' => $creator->name ?: $hit['name'],
            'country' => $creator->country ?: $hit['country'],
            'niche' => $creator->niche ?: $niche,
            'followers' => $hit['followers'] ?? $creator->followers,
            'emails' => array_values(array_unique([...($creator->emails ?? []), ...$hit['emails']])),
            'whatsapp' => $creator->whatsapp ?: ($hit['socials']['whatsapp'] ?? null),
            'website' => $creator->website ?: $hit['website'],
            'bio' => $creator->bio ?: $hit['summary'],
        ]);
        if ($platform === 'instagram' && ProfileEnricher::instagramReady($store)) {
            $this->instagram($store, $creator);
        }
        $this->score($creator);
        $creator->save();

        return $isNew;
    }

    /** Business / creator account details from Meta (bio, website, exact follower count). */
    private function instagram(Store $store, Creator $creator): void
    {
        try {
            $ig = $this->profiles->instagram($store, $creator->handle);
        } catch (\Throwable $e) {
            report($e);

            return;
        }
        if (! $ig) {
            return;
        }
        $bio = WebSearchSource::contacts((string) $ig['biography']);
        $creator->fill([
            'name' => $ig['name'] ?: $creator->name,
            'followers' => $ig['followers'] ?? $creator->followers,
            'bio' => $ig['biography'] ?: $creator->bio,
            'website' => $ig['website'] ?: $creator->website,
            'emails' => array_values(array_unique([...($creator->emails ?? []), ...($bio['emails'] ?? [])])),
            'whatsapp' => $creator->whatsapp ?: ($bio['socials']['whatsapp'] ?? null),
        ]);
    }

    /**
     * 0–100 for a collaboration:
     *   audience ≤ 35  micro creators (10K–100K) convert best per dollar; mega accounts cost more and convert less
     *   niche    ≤ 30  the niche keyword is in their name or bio
     *   contact  ≤ 30  email (20) / WhatsApp (10): a DM alone is easy to miss
     */
    public function score(Creator $c): void
    {
        $reasons = [];
        $f = $c->followers;
        [$audience, $why] = match (true) {
            $f === null => [10, '粉丝数未知'],
            $f < 1000 => [5, '粉丝少于 1 千'],
            $f < 10000 => [25, '小达人（1 千–1 万粉丝），合作成本低'],
            $f < 100000 => [35, '腰部达人（1 万–10 万粉丝），性价比最高'],
            $f < 500000 => [25, '头部达人（10 万–50 万粉丝）'],
            default => [15, '超大号（50 万粉丝以上），费用高'],
        };
        $reasons[] = $why;

        $text = mb_strtolower($c->name.' '.$c->bio);
        $cover = $c->niche ? MarketFit::covers($c->niche, $text) : 0;
        $niche = $cover >= 1 ? 30 : ($cover > 0 ? 18 : 8);
        if ($cover > 0) {
            $reasons[] = '简介和「'.$c->niche.'」相关';
        }

        $contact = 0;
        if ($c->emails) {
            $contact += 20;
            $reasons[] = '有邮箱';
        }
        if ($c->whatsapp) {
            $contact += 10;
            $reasons[] = '有 WhatsApp';
        }
        if (! $contact) {
            $reasons[] = '只能私信联系';
        }
        $c->score = min(100, $audience + $niche + $contact);
        $c->score_reasons = $reasons;
    }

    /**
     * First message in the creator's language: a free sample, a commission on the sales they bring and a
     * discount code for their followers. Claude writes it when the store has a key; otherwise a template.
     *
     * @return array{subject: string, message: string, message_zh: ?string, code: string, ai: bool}
     */
    public function outreach(Store $store, Creator $c): array
    {
        $rate = Affiliates::settings($store)['default_rate'];
        $code = self::suggestCode($c);
        $lang = LeadProfiles::countries()[$c->country ?? '']['lang'] ?? 'en';
        $sender = LeadSettings::sender($store);
        if (LeadAi::ready($store)) {
            $kit = \App\Content\BrandKit::for($store)->all();
            $profile = json_encode(['platform' => $c->platform, 'name' => $c->name, 'handle' => $c->handle, 'followers' => $c->followers, 'bio' => Str::limit((string) $c->bio, 600, ''), 'niche' => $c->niche], JSON_UNESCAPED_UNICODE);
            $prompt = <<<TXT
            Write a first collaboration message from the online store "{$store->name}" ({$store->storefrontUrl()}, positioning: {$kit['positioning']}, voice: {$kit['voice']})
            to this creator, in language code "{$lang}". Sender: {$sender['name']} {$sender['title']}.
            Creator (JSON): {$profile}

            The offer: a free product to try, {$rate}% commission on every sale through their link, and the code "{$code}" giving their followers a discount.
            Rules: friendly and short (max 120 words), mention something specific from their profile, no pressure, no invented facts about the store,
            no fake urgency, ask if they'd like to receive a sample. Also give a subject line (for email) and a Simplified Chinese translation of the message.
            TXT;
            $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['subject', 'message', 'message_zh'],
                'properties' => ['subject' => ['type' => 'string'], 'message' => ['type' => 'string'], 'message_zh' => ['type' => 'string']]];
            try {
                $r = app(LeadAi::class)->json($store, $prompt, $schema, 'low', 2000);

                return ['subject' => (string) $r['subject'], 'message' => (string) $r['message'], 'message_zh' => (string) $r['message_zh'], 'code' => $code, 'ai' => true];
            } catch (RuntimeException $e) {
                report($e);
            }
        }

        return [...self::template($store, $c, $lang, $rate, $code), 'code' => $code, 'ai' => false];
    }

    private static function template(Store $store, Creator $c, string $lang, float $rate, string $code): array
    {
        $first = Str::before($c->name, ' ') ?: $c->handle;
        $r = rtrim(rtrim(number_format($rate, 1), '0'), '.');
        $t = match ($lang) {
            'es' => ['Colaboración con '.$store->name, "Hola {$first}, me encanta tu contenido sobre {$c->niche}. Somos {$store->name} y nos gustaría enviarte un producto gratis para que lo pruebes. Si te gusta, ganarías un {$r}% de comisión por cada venta con tu enlace, y tus seguidores tendrían descuento con el código {$code}. ¿Te gustaría recibir una muestra?"],
            'pt' => ['Parceria com '.$store->name, "Oi {$first}, adoro seu conteúdo sobre {$c->niche}. Somos a {$store->name} e gostaríamos de enviar um produto grátis para você testar. Se gostar, você ganha {$r}% de comissão em cada venda pelo seu link, e seus seguidores têm desconto com o código {$code}. Quer receber uma amostra?"],
            default => ['Collaboration with '.$store->name, "Hi {$first}, I love your content about {$c->niche}. We're {$store->name} and would love to send you a free product to try. If you like it, you'd earn {$r}% commission on every sale through your link, and your followers get a discount with the code {$code}. Would you like to receive a sample?"],
        };

        return ['subject' => $t[0], 'message' => $t[1], 'message_zh' => null];
    }

    /** "@case.queen_mx" → "CASEQUEENMX" (unique among the store's affiliate codes once lower-cased). */
    public static function suggestCode(Creator $c): string
    {
        $base = Str::limit(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($c->handle))) ?: 'CREATOR', 20, '');
        $code = $base;
        for ($n = 2; Affiliate::where('code', mb_strtolower($code))->exists(); $n++) {
            $code = Str::limit($base, 17, '').$n;
        }

        return $code;
    }

    /** Agreed to collaborate: an affiliate with their own code and link. */
    public function toAffiliate(Store $store, Creator $c, string $email, ?string $code, ?float $rate): Affiliate
    {
        if ($c->affiliate_id && ($a = Affiliate::find($c->affiliate_id))) {
            return $a;
        }
        $code = mb_strtolower($code ?: self::suggestCode($c));
        if (Affiliate::where('code', $code)->exists()) {
            throw new RuntimeException(__('这个推广码已经被使用'));
        }
        $a = new Affiliate(['name' => Str::limit($c->name, 100, ''), 'email' => mb_strtolower(trim($email)), 'code' => $code,
            'commission_rate' => $rate ?? Affiliates::settings($store)['default_rate'], 'note' => Str::limit(ucfirst($c->platform).' '.$c->url, 300, '')]);
        $a->forceFill(['store_id' => $store->id, 'token' => Str::random(48), 'status' => 'active'])->save();
        $c->forceFill(['affiliate_id' => $a->id, 'status' => 'agreed'])->save();

        return $a;
    }
}
