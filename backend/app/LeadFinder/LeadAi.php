<?php

namespace App\LeadFinder;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Models\Lead;
use App\Models\Store;
use RuntimeException;

/**
 * Claude-powered lead work: judge how well a prospect fits the merchant's product (with reasons
 * in Chinese), and write a personalised first-contact email in the prospect's language.
 */
class LeadAi
{
    public const MODEL = 'claude-opus-5-5';

    public const LANG_NAMES = ['en' => 'English', 'es' => 'Spanish', 'pt' => 'Portuguese', 'de' => 'German', 'fr' => 'French', 'it' => 'Italian',
        'nl' => 'Dutch', 'pl' => 'Polish', 'tr' => 'Turkish', 'ru' => 'Russian', 'id' => 'Indonesian', 'vi' => 'Vietnamese', 'ar' => 'Arabic'];

    public static function ready(Store $store): bool
    {
        return LeadSettings::anthropicKey($store) !== null;
    }

    /** @return array{fit: int, segment: string, summary: string, reasons: string[]} */
    public function analyze(Store $store, Lead $lead): array
    {
        $profile = LeadProfiles::get($lead->search?->industry ?? 'custom');
        $sender = LeadSettings::sender($store);
        $product = trim(($sender['products'] ?: $profile['pitch']) ?: ($lead->search ? implode(', ', $lead->search->keywords) : ''));

        $prompt = "We are a manufacturer looking for B2B buyers. Our products: {$product}.\n"
            ."Judge whether the business below is a likely buyer (wholesaler/distributor/importer, repair shop, retailer, e-commerce seller or brand that would purchase our products).\n\n"
            .$this->describe($lead)
            ."\n\nReturn JSON. fit: 0-100 (90+ = clearly sells/uses exactly our product category and buys in volume; 60-89 = sells closely related products; 30-59 = possible but unclear; <30 = unrelated). "
            .'segment: one of wholesale, repair, ecommerce, retail, brand, other. summary: one sentence in Simplified Chinese describing what this business does. '
            .'reasons: 2-4 short reasons in Simplified Chinese (evidence from their listing/site, e.g. 官网有批发页面, 主营手机配件). Base everything only on the information given.';

        $data = $this->json($store, $prompt, [
            'type' => 'object',
            'properties' => [
                'fit' => ['type' => 'integer'],
                'segment' => ['type' => 'string', 'enum' => ['wholesale', 'repair', 'ecommerce', 'retail', 'brand', 'other']],
                'summary' => ['type' => 'string'],
                'reasons' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['fit', 'segment', 'summary', 'reasons'],
            'additionalProperties' => false,
        ], 'low', 4000);

        return [
            'fit' => max(0, min(100, (int) $data['fit'])),
            'segment' => $data['segment'],
            'summary' => mb_substr((string) $data['summary'], 0, 300),
            'reasons' => array_slice(array_map(fn ($r) => mb_substr((string) $r, 0, 80), (array) $data['reasons']), 0, 4),
        ];
    }

    /** @return array{language: string, subject: string, body: string, subject_zh: string, body_zh: string} */
    public function outreach(Store $store, Lead $lead, ?string $language = null): array
    {
        $profile = LeadProfiles::get($lead->search?->industry ?? 'custom');
        $s = LeadSettings::sender($store);
        $language = $language ?: (LeadProfiles::countries()[$lead->country]['lang'] ?? 'en');
        $langName = self::LANG_NAMES[$language] ?? 'English';
        $me = collect([
            'Company' => $s['company'], 'Sender name' => $s['name'], 'Sender title' => $s['title'],
            'Products' => $s['products'] ?: $profile['pitch'], 'Strengths' => $s['advantages'], 'MOQ / terms' => $s['moq'],
            'Website' => $s['website'], 'WhatsApp' => $s['whatsapp'], 'Email' => $s['email'],
        ])->filter()->map(fn ($v, $k) => "$k: $v")->implode("\n");

        $prompt = "Write a short first-contact B2B email from a manufacturer to a potential buyer.\n\nSENDER (us):\n{$me}\n\nRECIPIENT:\n".$this->describe($lead)
            ."\n\nRules: write in {$langName}. 90-150 words. Open with one specific, true detail about the recipient's business taken from the information above (no flattery, nothing invented). "
            .'Say in one or two sentences what we make and why it fits them (their customer type). Offer one low-friction next step (free samples, catalog and price list, or a quick WhatsApp chat). '
            .'Plain text, no markdown, no placeholders in brackets — if a sender detail is missing, leave it out. Sign off with the sender name and company. '
            .'Subject line: under 8 words, specific, not salesy. Also give a Simplified Chinese translation of the subject and body so the sender can check it.';

        $data = $this->json($store, $prompt, [
            'type' => 'object',
            'properties' => ['subject' => ['type' => 'string'], 'body' => ['type' => 'string'], 'subject_zh' => ['type' => 'string'], 'body_zh' => ['type' => 'string']],
            'required' => ['subject', 'body', 'subject_zh', 'body_zh'],
            'additionalProperties' => false,
        ], 'medium', 8000);

        return ['language' => $language, ...array_map(fn ($v) => trim((string) $v), $data)];
    }

    /**
     * "帮我找墨西哥做手机维修的批发商" → a ready-to-run search: profile, country, cities and keywords
     * in the market's own language, plus a one-line explanation in Chinese.
     *
     * @return array{industry: string, country: string, cities: string[], keywords: string[], channels: string[], explain: string}
     */
    public function plan(Store $store, string $request): array
    {
        $profiles = collect(LeadProfiles::all())->map(fn ($p, $k) => "{$k}: {$p['label']}")->implode('; ');
        $countries = collect(LeadProfiles::countries())->map(fn ($c, $k) => "{$k} ({$c['en']}, language: {$c['lang']})")->implode('; ');
        $sender = LeadSettings::sender($store);
        $prompt = "A manufacturer describes the B2B buyers they want to find. Turn it into a business-listing search.\n\n"
            ."Request: {$request}\n".($sender['products'] ? "Their products: {$sender['products']}\n" : '')
            ."\nProduct profiles: {$profiles}\nCountries (ISO code, name, language): {$countries}\n\n"
            .'Return JSON: industry = one profile key (use custom when no profile fits); country = one ISO code from the list (pick the best market if none is named); '
            .'cities = 1-4 major commercial cities in that country relevant to the request (empty only if the request asks for the whole country); '
            .'keywords = 3-6 search phrases a local person would type into Google Maps to find these businesses, in the local language, plus up to 2 in English; '
            .'channels = where these buyers are easiest to find, 1-3 of: maps (shops with a physical location), web (company websites: importers, distributors, online stores), '
            .'facebook, instagram, tiktok (small brands and online sellers that sell through social media), linkedin (larger importers and distributors); '
            .'explain = one short sentence in Simplified Chinese describing who will be searched for and where.';
        $data = $this->json($store, $prompt, [
            'type' => 'object',
            'properties' => [
                'industry' => ['type' => 'string', 'enum' => array_keys(LeadProfiles::all())],
                'country' => ['type' => 'string', 'enum' => array_keys(LeadProfiles::countries())],
                'cities' => ['type' => 'array', 'items' => ['type' => 'string']],
                'keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                'channels' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['maps', 'web', 'facebook', 'instagram', 'tiktok', 'linkedin']]],
                'explain' => ['type' => 'string'],
            ],
            'required' => ['industry', 'country', 'cities', 'keywords', 'channels', 'explain'],
            'additionalProperties' => false,
        ], 'low', 4000);
        $clean = fn ($list) => array_slice(array_values(array_filter(array_map(fn ($v) => mb_substr(trim((string) $v), 0, 80), (array) $list))), 0, 8);

        return ['industry' => $data['industry'], 'country' => $data['country'], 'cities' => $clean($data['cities']),
            'keywords' => $clean($data['keywords']), 'channels' => array_values(array_unique(array_intersect((array) ($data['channels'] ?? []), ['maps', 'web', 'facebook', 'instagram', 'tiktok', 'linkedin']))) ?: ['maps'],
            'explain' => mb_substr((string) $data['explain'], 0, 200)];
    }

    /** No AI key: a solid English template filled with what we know. */
    public static function templateOutreach(Store $store, Lead $lead): array
    {
        $s = LeadSettings::sender($store);
        $profile = LeadProfiles::get($lead->search?->industry ?? 'custom');
        $products = $s['products'] ?: ($profile['pitch'] ? 'phone screen protectors (tempered glass, hydrogel, privacy film)' : 'our products');
        $segmentLine = match ($lead->segment) {
            'wholesale' => 'As a distributor, you can count on factory-direct pricing, stable stock and OEM / private-label packaging.',
            'repair' => 'Repair shops like yours usually need reliable, easy-to-install protectors for the most popular models — we keep them in stock.',
            'ecommerce' => 'For online sellers we offer retail-ready packaging, product photos and fast restocking.',
            default => 'We supply retailers with factory-direct pricing, retail-ready packaging and fast delivery.',
        };
        $body = "Hi {$lead->name} team,\n\n"
            ."I came across {$lead->name}".($lead->city ? " in {$lead->city}" : '')." and thought you might be interested in {$products}.\n\n"
            .$segmentLine.($s['advantages'] ? ' '.$s['advantages'] : '').($s['moq'] ? " MOQ: {$s['moq']}." : '')."\n\n"
            ."Would you like our catalog and price list, or a few free samples to test the quality?\n\n"
            ."Best regards,\n".trim(($s['name'] ?: '')."\n".$s['company'])
            .($s['website'] ? "\n".$s['website'] : '').($s['whatsapp'] ? "\nWhatsApp: ".$s['whatsapp'] : '');

        return ['language' => 'en', 'subject' => 'Factory-direct '.(str_contains($products, 'protector') ? 'screen protectors' : 'supply').' for '.$lead->name,
            'body' => $body, 'subject_zh' => '', 'body_zh' => ''];
    }

    private function describe(Lead $lead): string
    {
        return collect([
            'Name' => $lead->name, 'Listing category' => $lead->category,
            'Location' => trim(implode(', ', array_filter([$lead->city, LeadProfiles::countries()[$lead->country]['en'] ?? $lead->country]))),
            'Website' => $lead->website, 'Google rating' => $lead->rating ? "{$lead->rating} ({$lead->reviews_count} reviews)" : null,
            'Site platform' => $lead->signals ? implode(', ', $lead->signals) : null,
            'Site description' => $lead->summary, 'Site text (excerpt)' => $lead->site_text ? mb_substr($lead->site_text, 0, 2500) : null,
        ])->filter()->map(fn ($v, $k) => "$k: $v")->implode("\n");
    }

    /** Structured (JSON schema) answer from Claude with the store's key; also used by product collection. */
    public function json(Store $store, string $prompt, array $schema, string $effort, int $maxTokens): array
    {
        $key = LeadSettings::anthropicKey($store);
        if (! $key) {
            throw new RuntimeException('还没有配置 AI（Claude API Key）');
        }
        $workspace = LeadSettings::anthropicWorkspace($store);
        $client = new Client(apiKey: $key, requestOptions: $workspace ? ['extraHeaders' => ['anthropic-workspace-id' => $workspace]] : null);
        $messages = [['role' => 'user', 'content' => $prompt]];
        $outputConfig = ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => $schema]];
        try {
            try {
                // Server-side fallback rescues a declined request on another model; it is a beta not every account has
                $message = $client->beta->messages->create(model: self::MODEL, maxTokens: $maxTokens, messages: $messages,
                    outputConfig: $outputConfig, fallbacks: 'default', betas: ['server-side-fallback-2026-07-01']);
            } catch (BadRequestException $e) {
                report($e);
                // Rejected (e.g. the account isn't enrolled in that beta): the same request on the standard endpoint
                $message = $client->messages->create(model: self::MODEL, maxTokens: $maxTokens, messages: $messages, outputConfig: $outputConfig);
            }
        } catch (AuthenticationException) {
            throw new RuntimeException('Claude API Key 无效，请在获客设置里检查');
        } catch (PermissionDeniedException|NotFoundException $e) {
            report($e);
            throw new RuntimeException('这个 Claude 账号无权使用模型 '.self::MODEL.'：'.self::reason($e));
        } catch (RateLimitException) {
            throw new RuntimeException('AI 请求太频繁，请稍后再试');
        } catch (APIStatusException $e) {
            report($e);
            throw new RuntimeException(self::explain($e));
        } catch (APIConnectionException) {
            throw new RuntimeException('连接不上 AI 服务，请检查服务器网络');
        }

        $stop = $message->stopReason instanceof \BackedEnum ? $message->stopReason->value : $message->stopReason;
        if ($stop === 'refusal') {
            throw new RuntimeException('AI 拒绝处理这条线索');
        }
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }
        throw new RuntimeException('AI 返回内容无法解析，请重试');
    }

    /** One tiny request to confirm the key, the account's billing and model access all work. */
    public function test(Store $store): string
    {
        $data = $this->json($store, 'Reply with {"ok": true}.', [
            'type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok'], 'additionalProperties' => false,
        ], 'low', 1000);

        return ! empty($data['ok']) ? 'ok' : 'unexpected';
    }

    /** Anthropic's own explanation from the error body (the exception message also carries the raw JSON). */
    private static function reason(APIStatusException $e): string
    {
        $body = is_array($e->body) ? $e->body : (is_string($e->body) ? json_decode($e->body, true) : null);
        $msg = $body['error']['message'] ?? null;
        if (! $msg && preg_match('/"message"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $e->getMessage(), $m)) {
            $msg = stripcslashes($m[1]);
        }

        return mb_substr((string) ($msg ?: $e->getMessage()), 0, 300);
    }

    /** Common account problems in plain Chinese, with Anthropic's own message for anything else. */
    private static function explain(APIStatusException $e): string
    {
        $reason = self::reason($e);

        return match (true) {
            (bool) preg_match('/credit balance|billing|purchase credits|insufficient/i', $reason) => 'Claude 账户余额不足：请到 console.anthropic.com 的 Billing 页面充值后再试',
            (bool) preg_match('/spend limit|usage limit|monthly limit/i', $reason) => 'Claude 账户已达到消费上限：请到 console.anthropic.com 提高 Limits 后再试',
            (bool) preg_match('/not scoped to a workspace|anthropic-workspace-id/i', $reason) => '这个 Claude API Key 是组织级的，没有绑定工作区：请在获客设置里填写 Workspace ID（在 console.anthropic.com › Settings › Workspaces 里，以 wrkspc_ 开头），或者在某个工作区里新建一个 API Key 换上',
            (bool) preg_match('/workspace/i', $reason) && (bool) preg_match('/not found|invalid|does not exist/i', $reason) => 'Workspace ID 不正确：请到 console.anthropic.com › Settings › Workspaces 复制正确的 ID（以 wrkspc_ 开头）',
            (bool) preg_match('/overloaded/i', $reason) => 'AI 服务繁忙，请稍后再试',
            default => 'AI 服务报错：'.$reason,
        };
    }
}
