<?php

namespace App\Themes;

use App\Models\Collection;
use App\Models\StoreMedia;
use App\Models\StoreTheme;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Theme packages: export a library theme as a .zip (theme.json + its images) and read uploads back —
 * either a Lumée package (from this or another store) or a Shopify theme (converted).
 *
 * theme.json = { format: "lumee-theme", version: 1, name, theme, config: {settings, sections},
 *                images: {"<old media id>": "images/<file>"}, collections: {"<old id>": "<handle>"} }
 *
 * Uploads are read in memory by entry name (nothing is extracted to disk), with size limits per entry.
 */
class ThemePackage
{
    public const FORMAT = 'lumee-theme';

    private const MAX_JSON = 2 * 1024 * 1024;

    private const MAX_IMAGE = 10 * 1024 * 1024;

    private const MAX_IMAGES = 40;

    private const MAX_SHOPIFY_FILES = 60;

    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public function __construct(private ThemeService $themes) {}

    /** Build the zip for a theme; returns the temp file path. */
    public function export(StoreTheme $row): string
    {
        $config = $row->draft ?? $row->published ?? $this->themes->defaults($row->theme);
        $zip = new ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'theme').'.zip';
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $images = [];
        $ids = $this->mediaIds($config);
        foreach (StoreMedia::whereIn('id', $ids)->get() as $m) {
            $bytes = rescue(fn () => Storage::disk($m->disk)->get($m->path), null, false);
            if ($bytes === null) {
                continue;
            }
            $file = 'images/'.$m->id.'.'.(pathinfo($m->path, PATHINFO_EXTENSION) ?: 'jpg');
            $zip->addFromString($file, $bytes);
            $images[(string) $m->id] = $file;
        }

        $collectionIds = $this->collectionIds($config);
        $collections = $collectionIds ? Collection::whereIn('id', $collectionIds)->pluck('handle', 'id')->mapWithKeys(fn ($h, $id) => [(string) $id => $h])->all() : [];

        $zip->addFromString('theme.json', json_encode([
            'format' => self::FORMAT,
            'version' => 1,
            'name' => $row->name ?: config("themes.{$row->theme}.name"),
            'theme' => $row->theme,
            'exported_at' => now()->toIso8601String(),
            'config' => $config,
            'images' => (object) $images,
            'collections' => (object) $collections,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return $path;
    }

    /**
     * Read an uploaded package. Returns the theme to create (not yet saved).
     *
     * @return array{source: string, name: string, theme: string, config: array, report: array}
     */
    public function import(UploadedFile $file, string $shopifyBase): array
    {
        $files = $this->read($file);

        $lumee = $this->find($files, 'theme.json');
        $pkg = $lumee !== null ? json_decode($lumee, true) : null;
        if (is_array($pkg) && ($pkg['format'] ?? null) === self::FORMAT) {
            return $this->fromPackage($pkg, $files);
        }

        $isShopify = collect(array_keys($files))->contains(fn ($p) => preg_match('~(^|/)(config/settings_data|templates/index)\.json$~', $p));
        if ($isShopify) {
            $result = app(ShopifyThemeConverter::class)->convert($files, $shopifyBase);

            return [
                'source' => 'shopify',
                'name' => $this->shopifyName($file),
                'theme' => $shopifyBase,
                'config' => $this->themes->sanitize($shopifyBase, $result['config']),
                'report' => $result['report'],
            ];
        }

        throw ValidationException::withMessages(['file' => __('无法识别这个文件：请上传 Lumée 导出的主题包，或 Shopify 主题 .zip。')]);
    }

    /**
     * Check a Lumée package without saving anything (marketplace uploads have no store).
     *
     * @return array{name: string, theme: string, swatches: string[]}
     */
    public function inspect(UploadedFile $file): array
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'zip') {
            throw ValidationException::withMessages(['file' => __('请上传在主题库「导出」得到的 .zip 主题包')]);
        }
        $files = $this->read($file);
        $raw = $this->find($files, 'theme.json');
        $pkg = $raw !== null ? json_decode($raw, true) : null;
        if (! is_array($pkg) || ($pkg['format'] ?? null) !== self::FORMAT) {
            throw ValidationException::withMessages(['file' => __('请上传在主题库「导出」得到的 .zip 主题包')]);
        }
        $theme = $pkg['theme'] ?? null;
        if (! is_string($theme) || ! config("themes.$theme")) {
            throw ValidationException::withMessages(['file' => __('这个主题包基于的模板在本平台不存在。')]);
        }
        $config = $this->themes->sanitize($theme, is_array($pkg['config'] ?? null) ? $pkg['config'] : []);
        $c = $config['settings']['colors'] ?? [];

        return [
            'name' => Str::limit(is_string($pkg['name'] ?? null) ? trim($pkg['name']) : '', 70, ''),
            'theme' => $theme,
            'swatches' => array_values(array_filter([$c['primary'] ?? null, $c['background'] ?? null, $c['text'] ?? null, $c['accent'] ?? null], 'is_string')),
        ];
    }

    private function fromPackage(array $pkg, array $files): array
    {
        $theme = $pkg['theme'] ?? null;
        if (! is_string($theme) || ! config("themes.$theme")) {
            throw ValidationException::withMessages(['file' => __('这个主题包基于的模板在本平台不存在。')]);
        }
        $config = is_array($pkg['config'] ?? null) ? $pkg['config'] : [];

        // Images: store each packaged file as this store's media, then point the config at the new ids
        $map = [];
        $skipped = 0;
        foreach (array_slice((array) ($pkg['images'] ?? []), 0, self::MAX_IMAGES, true) as $oldId => $name) {
            $bytes = is_string($name) ? ($files[$name] ?? $this->find($files, $name)) : null;
            $media = $bytes !== null ? $this->storeImage($bytes) : null;
            $media ? $map[(int) $oldId] = $media->id : $skipped++;
        }
        $collections = [];
        foreach ((array) ($pkg['collections'] ?? []) as $oldId => $handle) {
            $collections[(int) $oldId] = is_string($handle) ? Collection::where('handle', $handle)->value('id') : null;
        }
        $config = $this->remap($config, $map, $collections);

        $notes = [];
        $skipped && $notes[] = __(':p0 张图片无法读取，已跳过。', ['p0' => $skipped]);
        $missing = count(array_filter($collections, fn ($v) => $v === null));
        $missing && $notes[] = __(':p0 个分类在本店铺不存在（按 handle 匹配），相关模块会显示最新商品。', ['p0' => $missing]);

        $clean = $this->themes->sanitize($theme, $config);

        return [
            'source' => 'import',
            'name' => Str::limit(is_string($pkg['name'] ?? null) ? trim($pkg['name']) : config("themes.$theme.name"), 70, ''),
            'theme' => $theme,
            'config' => $clean,
            'report' => [
                'sections' => collect($clean['sections'])->map(fn ($s) => ['from' => $s['type'], 'to' => $s['type']])->all(),
                'settings' => [], 'images' => count($map), 'notes' => $notes,
            ],
        ];
    }

    /** @return array<string, string> entry name => contents (only .json and images, size-limited) */
    private function read(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext === 'json') {
            if ($file->getSize() > self::MAX_JSON) {
                throw ValidationException::withMessages(['file' => __('文件太大')]);
            }
            $content = (string) file_get_contents($file->getRealPath());
            $data = json_decode(preg_replace('~^\s*/\*.*?\*/~s', '', $content), true);
            // A single Shopify file: settings_data.json ("current") or a JSON template ("sections"+"order")
            $name = match (true) {
                is_array($data) && ($data['format'] ?? null) === self::FORMAT => 'theme.json',
                is_array($data) && isset($data['current']) => 'config/settings_data.json',
                is_array($data) && isset($data['sections'], $data['order']) => 'templates/index.json',
                default => 'unknown.json',
            };

            return [$name => $content];
        }

        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath(), ZipArchive::RDONLY) !== true) {
            throw ValidationException::withMessages(['file' => __('无法打开这个压缩包')]);
        }
        $out = [];
        $jsonCount = 0;
        for ($i = 0; $i < min($zip->numFiles, 5000); $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', $stat['name']);
            if (str_ends_with($name, '/') || str_contains($name, '__MACOSX')) {
                continue;
            }
            $isJson = preg_match('~(^|/)(theme\.json|config/settings_data\.json|templates/index\.json|sections/header-group\.json|locales/[a-z-]+\.default(\.schema)?\.json)$~i', $name);
            $isImage = preg_match('~(^|/)images/[^/]+\.(jpe?g|png|webp|gif)$~i', $name);
            if ($isJson && $stat['size'] <= self::MAX_JSON && ++$jsonCount <= self::MAX_SHOPIFY_FILES) {
                $out[$name] = (string) $zip->getFromIndex($i);
            } elseif ($isImage && $stat['size'] <= self::MAX_IMAGE && count($out) < 200) {
                $out[$name] = (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();

        return $out;
    }

    private function storeImage(string $bytes): ?StoreMedia
    {
        if (strlen($bytes) > self::MAX_IMAGE) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        $ext = $info ? (self::IMAGE_TYPES[$info['mime']] ?? null) : null;
        if (! $ext || $info[0] > 8000 || $info[1] > 8000) {
            return null;
        }
        $disk = config('lumee.media_disk');
        $path = 'stores/'.app(\App\Tenancy\CurrentStore::class)->id().'/theme/'.Str::uuid().'.'.$ext;
        Storage::disk($disk)->put($path, $bytes, ['visibility' => 'public']);

        return StoreMedia::create(['disk' => $disk, 'path' => $path, 'width' => $info[0], 'height' => $info[1], 'size' => strlen($bytes)]);
    }

    /** Swap old image / collection ids for this store's. Unknown ids become null. */
    private function remap(array $config, array $media, array $collections): array
    {
        $walk = function (array $values) use ($media, $collections) {
            foreach ($values as $k => $v) {
                if (in_array($k, ['image', 'logo'], true)) {
                    $values[$k] = is_numeric($v) ? ($media[(int) $v] ?? null) : null;
                } elseif (is_string($k) && str_starts_with($k, 'collection')) {
                    $values[$k] = is_numeric($v) ? ($collections[(int) $v] ?? null) : null;
                }
            }

            return $values;
        };
        foreach ((array) ($config['settings'] ?? []) as $g => $values) {
            is_array($values) && $config['settings'][$g] = $walk($values);
        }
        foreach ((array) ($config['sections'] ?? []) as $i => $s) {
            if (is_array($s['settings'] ?? null)) {
                $config['sections'][$i]['settings'] = $walk($s['settings']);
            }
        }

        return $config;
    }

    private function mediaIds(array $config): array
    {
        $ids = [];
        array_walk_recursive($config, function ($v, $k) use (&$ids) {
            if (in_array($k, ['image', 'logo'], true) && is_int($v)) {
                $ids[] = $v;
            }
        });

        return array_values(array_unique($ids));
    }

    private function collectionIds(array $config): array
    {
        $ids = [];
        foreach ($config['sections'] ?? [] as $s) {
            foreach ($s['settings'] ?? [] as $k => $v) {
                if (str_starts_with((string) $k, 'collection') && is_int($v)) {
                    $ids[] = $v;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function find(array $files, string $suffix): ?string
    {
        foreach ($files as $path => $content) {
            if ($path === $suffix || str_ends_with($path, '/'.$suffix)) {
                return $content;
            }
        }

        return null;
    }

    private function shopifyName(UploadedFile $file): string
    {
        $base = trim(preg_replace('/\.(zip|json)$/i', '', $file->getClientOriginalName()));

        return Str::limit(($base !== '' ? $base : 'Shopify').' (Shopify)', 70, '');
    }
}
