<?php

namespace App\Content;

use App\Models\Store;
use Illuminate\Support\Facades\Crypt;

/**
 * The store's brand rules (stores.settings['brand_kit']). AI rewriting, the content score and the
 * image standardizer all read from here, so every product ends up looking like one brand.
 */
final class BrandKit
{
    public const RATIOS = ['3:4', '4:5', '1:1'];

    /** Detail-page sections, in the order the merchant chose. */
    public const SECTIONS = ['highlights', 'details', 'materials', 'size', 'care', 'shipping', 'faq'];

    public function __construct(private Store $store) {}

    public static function for(Store $store): self
    {
        return new self($store);
    }

    public function all(): array
    {
        $k = (array) ($this->store->settings['brand_kit'] ?? []);

        return [
            'positioning' => (string) ($k['positioning'] ?? ''),
            'audience' => (string) ($k['audience'] ?? ''),
            'voice' => (string) ($k['voice'] ?? ''),
            'banned_words' => array_values(array_filter(array_map('trim', (array) ($k['banned_words'] ?? [])))),
            'title_pattern' => (string) ($k['title_pattern'] ?? ''),
            'title_min' => (int) ($k['title_min'] ?? 30),
            'title_max' => (int) ($k['title_max'] ?? 90),
            'ratio' => in_array($k['ratio'] ?? null, self::RATIOS, true) ? $k['ratio'] : '3:4',
            'background' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($k['background'] ?? '')) ? strtoupper($k['background']) : '#F7F5F2',
            'padding' => max(0, min(30, (int) ($k['padding'] ?? 8))),
            'min_images' => max(1, min(12, (int) ($k['min_images'] ?? 4))),
            'sections' => array_values(array_intersect((array) ($k['sections'] ?? ['highlights', 'details', 'materials', 'size', 'care']), self::SECTIONS)),
            'min_score' => max(0, min(100, (int) ($k['min_score'] ?? 0))),
            'removebg_connected' => ! empty($k['removebg_key']),
        ];
    }

    public function save(array $data): void
    {
        $settings = $this->store->settings ?? [];
        $old = (array) ($settings['brand_kit'] ?? []);
        $kit = [...$old, ...array_intersect_key($data, array_flip([
            'positioning', 'audience', 'voice', 'banned_words', 'title_pattern', 'title_min', 'title_max',
            'ratio', 'background', 'padding', 'min_images', 'sections', 'min_score',
        ]))];
        // remove.bg key: "" removes it, null/absent keeps it; stored encrypted
        if (array_key_exists('removebg_key', $data) && $data['removebg_key'] !== null) {
            $kit['removebg_key'] = $data['removebg_key'] === '' ? null : Crypt::encryptString($data['removebg_key']);
        }
        $settings['brand_kit'] = $kit;
        $this->store->settings = $settings;
        $this->store->save();
    }

    public function removeBgKey(): ?string
    {
        $enc = $this->store->settings['brand_kit']['removebg_key'] ?? null;

        return $enc ? rescue(fn () => Crypt::decryptString($enc), null, false) : null;
    }

    /** "3:4" → 0.75 (width / height) */
    public static function ratio(string $ratio): float
    {
        [$w, $h] = array_map('intval', explode(':', $ratio));

        return $w / max(1, $h);
    }

    /** Words the brand never uses: the kit's own, plus supplier noise. */
    public function bannedWords(): array
    {
        return array_values(array_unique([...$this->all()['banned_words'],
            'hot sale', 'free shipping', 'wholesale', 'dropshipping', 'best quality', 'cheap', '100% original', '爆款', '包邮', '厂家直销']));
    }
}
