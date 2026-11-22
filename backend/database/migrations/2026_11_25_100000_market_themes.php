<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store design per market (phase A of docs/STOREFRONT-DESIGN-PLAN.md):
 *  - store_theme_markets: what a market changes on top of a library theme — setting overrides,
 *    home-section field overrides, or a home page of its own (draft / published, like the theme)
 *  - theme_versions: a snapshot on every publish (theme or market), restorable into the draft
 * The ten per-market fields that lived in markets.settings.theme (announcement, perks, colours,
 * hero texts…) move into the live theme's market layer, so the storefront looks the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_theme_markets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_theme_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->json('draft')->nullable();       // {settings: {group: {field: value}}, section_overrides: {id: {field: value}}, sections: null|[...]}
            $table->json('published')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['store_theme_id', 'market_id']);
        });

        Schema::create('theme_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_theme_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_id')->nullable()->constrained()->nullOnDelete();
            $table->json('config');
            $table->string('source', 20);           // publish | restore | auto | ai
            $table->string('note', 200)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['store_theme_id', 'market_id', 'id']);
        });

        $this->moveMarketTexts();
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_versions');
        Schema::dropIfExists('store_theme_markets');
    }

    private function moveMarketTexts(): void
    {
        $paths = [
            'announcement_text' => ['header', 'announcement_text'], 'announcement_link' => ['header', 'announcement_link'],
            'perks' => ['product', 'perks'], 'tagline' => ['footer', 'tagline'], 'primary' => ['colors', 'primary'], 'accent' => ['colors', 'accent'],
        ];
        $hero = ['hero_eyebrow' => 'eyebrow', 'hero_heading' => 'heading', 'hero_text' => 'text', 'hero_button' => 'button_label'];
        $themes = app(\App\Themes\ThemeService::class);

        foreach (DB::table('markets')->whereNotNull('settings')->get() as $m) {
            $settings = json_decode((string) $m->settings, true) ?: [];
            $texts = array_filter((array) ($settings['theme'] ?? []), fn ($v) => is_string($v) && trim($v) !== '');
            unset($settings['theme']);
            DB::table('markets')->where('id', $m->id)->update(['settings' => json_encode($settings)]);
            if (! $texts) {
                continue;
            }
            $store = \App\Models\Store::find($m->store_id);
            if (! $store) {
                continue;
            }
            $row = $themes->ensureLive($store);
            $base = $row->published ?? $row->draft ?? $themes->defaults($row->theme);
            $layer = ['settings' => [], 'section_overrides' => [], 'sections' => null];
            foreach ($paths as $field => [$group, $key]) {
                if (isset($texts[$field])) {
                    $layer['settings'][$group][$key] = $texts[$field];
                }
            }
            if (isset($texts['announcement_text'])) {
                $layer['settings']['header']['announcement_enabled'] = true;   // a market announcement showed even if the store hid its own
            }
            foreach ($base['sections'] ?? [] as $s) {
                if (($s['type'] ?? null) === 'hero') {
                    foreach ($hero as $field => $key) {
                        if (isset($texts[$field])) {
                            $layer['section_overrides'][$s['id']][$key] = $texts[$field];
                        }
                    }
                }
            }
            DB::table('store_theme_markets')->insert([
                'store_id' => $store->id, 'store_theme_id' => $row->id, 'market_id' => $m->id,
                'draft' => json_encode($layer), 'published' => json_encode($layer), 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
};
