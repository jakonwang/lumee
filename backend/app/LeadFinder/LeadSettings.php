<?php

namespace App\LeadFinder;

use App\Models\Store;
use Illuminate\Support\Facades\Crypt;

/**
 * Per-store lead finder settings (store.settings.lead_finder): API keys (encrypted),
 * auto AI analysis, and the merchant's own profile used to write outreach emails.
 * A platform-wide key in config/services.php is used when the store has none.
 */
final class LeadSettings
{
    public const SENDER_FIELDS = ['company', 'name', 'title', 'products', 'advantages', 'moq', 'website', 'whatsapp', 'email'];

    public static function raw(Store $store): array
    {
        return ($store->settings['lead_finder'] ?? []) + ['google_key' => null, 'anthropic_key' => null, 'anthropic_workspace' => null, 'ai_auto' => true, 'sender' => [],
            'meta_token' => null, 'ig_user_id' => null, 'hunter_key' => null];
    }

    public static function googleKey(Store $store): ?string
    {
        return self::decrypt(self::raw($store)['google_key']) ?: (config('services.google_places.key') ?: null);
    }

    public static function anthropicKey(Store $store): ?string
    {
        return self::decrypt(self::raw($store)['anthropic_key']) ?: (config('services.anthropic.key') ?: null);
    }

    /** Organization-level keys (not tied to a workspace) must say which workspace to bill: sent as anthropic-workspace-id. */
    public static function anthropicWorkspace(Store $store): ?string
    {
        return self::decrypt(self::raw($store)['anthropic_key']) ? (self::raw($store)['anthropic_workspace'] ?: null) : (config('services.anthropic.workspace') ?: null);
    }

    /** Meta Graph API token and the merchant's own Instagram business account id (Instagram Business Discovery). */
    public static function metaToken(Store $store): ?string
    {
        return self::decrypt(self::raw($store)['meta_token']);
    }

    public static function igUserId(Store $store): ?string
    {
        return self::raw($store)['ig_user_id'] ?: null;
    }

    public static function hunterKey(Store $store): ?string
    {
        return self::decrypt(self::raw($store)['hunter_key']) ?: (config('services.hunter.key') ?: null);
    }

    /** Google web and social search runs on the research radar's SerpApi key. */
    public static function serpReady(Store $store): bool
    {
        return \App\Research\Radar::serpApiKey($store) !== null;
    }

    public static function aiAuto(Store $store): bool
    {
        return (bool) self::raw($store)['ai_auto'] && self::anthropicKey($store) !== null;
    }

    public static function sender(Store $store): array
    {
        $s = self::raw($store)['sender'] ?? [];

        return array_merge(array_fill_keys(self::SENDER_FIELDS, ''), ['company' => $store->name], array_filter($s, fn ($v) => $v !== null && $v !== ''));
    }

    /** Safe for the browser: never returns the keys themselves. */
    public static function public(Store $store): array
    {
        $r = self::raw($store);

        return [
            'google_key_set' => (bool) self::decrypt($r['google_key']),
            'anthropic_key_set' => (bool) self::decrypt($r['anthropic_key']),
            'google_platform' => ! self::decrypt($r['google_key']) && (bool) config('services.google_places.key'),
            'anthropic_platform' => ! self::decrypt($r['anthropic_key']) && (bool) config('services.anthropic.key'),
            'google_ready' => self::googleKey($store) !== null,
            'ai_ready' => self::anthropicKey($store) !== null,
            'serp_ready' => self::serpReady($store),
            'meta_token_set' => (bool) self::decrypt($r['meta_token']),
            'ig_user_id' => $r['ig_user_id'],
            'instagram_ready' => self::metaToken($store) !== null && self::igUserId($store) !== null,
            'hunter_key_set' => (bool) self::decrypt($r['hunter_key']),
            'hunter_ready' => self::hunterKey($store) !== null,
            'ai_auto' => (bool) $r['ai_auto'],
            'anthropic_workspace' => $r['anthropic_workspace'],
            'sender' => self::sender($store),
        ];
    }

    public static function save(Store $store, array $data): void
    {
        $old = self::raw($store);
        $key = fn (string $field) => array_key_exists($field, $data)
            ? ($data[$field] === '' || $data[$field] === null ? ($data[$field] === '' ? null : $old[$field]) : Crypt::encryptString(trim($data[$field])))
            : $old[$field];
        $store->settings = [...($store->settings ?? []), 'lead_finder' => [
            'google_key' => $key('google_key'),
            'anthropic_key' => $key('anthropic_key'),
            'meta_token' => $key('meta_token'),
            'hunter_key' => $key('hunter_key'),
            'ig_user_id' => array_key_exists('ig_user_id', $data) ? (preg_replace('/\D/', '', (string) $data['ig_user_id']) ?: null) : $old['ig_user_id'],
            'anthropic_workspace' => array_key_exists('anthropic_workspace', $data) ? (trim((string) $data['anthropic_workspace']) ?: null) : $old['anthropic_workspace'],
            'ai_auto' => (bool) ($data['ai_auto'] ?? $old['ai_auto']),
            'sender' => array_intersect_key($data['sender'] ?? $old['sender'], array_flip(self::SENDER_FIELDS)),
        ]];
        $store->save();
    }

    private static function decrypt(?string $v): ?string
    {
        if (! $v) {
            return null;
        }
        try {
            return Crypt::decryptString($v);
        } catch (\Throwable) {
            return null;
        }
    }
}
