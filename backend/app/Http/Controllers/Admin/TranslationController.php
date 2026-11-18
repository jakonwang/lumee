<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\I18n\Localization;
use App\I18n\TranslatableRegistry;
use App\Models\Translation;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Storefront languages (settings) and the content translation editor. */
class TranslationController extends Controller
{
    public function languages(CurrentStore $current): JsonResponse
    {
        return response()->json($this->languagesPayload($current));
    }

    public function updateLanguages(Request $request, CurrentStore $current): JsonResponse
    {
        $codes = array_keys(Localization::languages());
        $data = $request->validate([
            'default' => ['required', Rule::in($codes)],
            'published' => ['present', 'array'],
            'published.*' => [Rule::in($codes)],
        ]);
        $store = $current->getOrFail();
        $settings = $store->settings ?? [];
        $settings['languages'] = array_values(array_diff(array_unique($data['published']), [$data['default']]));
        $store->settings = $settings;
        $store->locale = $data['default'];
        $store->save();

        return response()->json($this->languagesPayload($current));
    }

    /** Resources of one type with their source text and this language's translations. */
    public function index(Request $request, CurrentStore $current, TranslatableRegistry $registry): JsonResponse
    {
        $store = $current->getOrFail();
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(TranslatableRegistry::TYPES))],
            'locale' => ['required', 'string'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $this->assertTranslatable($store, $data['locale']);

        $list = $registry->list($store, $data['type'], $data['q'] ?? null, (int) ($data['page'] ?? 1));
        $existing = Translation::where('type', $data['type'])->where('locale', $data['locale'])
            ->whereIn('resource_id', $list['items']->pluck('id'))->get()
            ->groupBy('resource_id')->map(fn ($g) => $g->pluck('value', 'field'));

        return response()->json([
            'data' => $list['items']->map(function ($item) use ($existing) {
                $values = $existing[$item['id']] ?? collect();
                $fields = array_map(fn ($f) => [...$f, 'multiline' => $f['multiline'] ?? false, 'value' => $values[$f['key']] ?? null], $item['fields']);

                return [...$item, 'fields' => $fields, 'done' => count(array_filter($fields, fn ($f) => filled($f['value']))), 'total' => count($fields)];
            }),
            'total' => $list['total'],
            'types' => \App\Support\Tr::map(TranslatableRegistry::TYPES),
        ]);
    }

    public function update(Request $request, CurrentStore $current, TranslatableRegistry $registry, string $type, int $id): JsonResponse
    {
        abort_unless(array_key_exists($type, TranslatableRegistry::TYPES), 404);
        $store = $current->getOrFail();
        $data = $request->validate([
            'locale' => ['required', 'string'],
            'fields' => ['required', 'array', 'max:500'],
            'fields.*' => ['nullable', 'string', 'max:100000'],
        ]);
        $this->assertTranslatable($store, $data['locale']);
        $allowed = $registry->allowedKeys($store, $type, $id);

        DB::transaction(function () use ($data, $allowed, $store, $type, $id) {
            foreach ($data['fields'] as $field => $value) {
                if (! in_array($field, $allowed, true)) {
                    continue;
                }
                $where = ['store_id' => $store->id, 'type' => $type, 'resource_id' => $id, 'locale' => $data['locale'], 'field' => $field];
                if ($value === null || trim($value) === '') {
                    Translation::where($where)->delete();   // empty = use the original text
                } else {
                    Translation::updateOrCreate($where, ['value' => $value]);
                }
            }
        });

        return response()->json(['saved' => true]);
    }

    private function assertTranslatable($store, string $locale): void
    {
        if ($locale === Localization::storeDefault($store) || ! array_key_exists($locale, Localization::languages())) {
            throw ValidationException::withMessages(['locale' => __('请选择默认语言以外的语言')]);
        }
    }

    private function languagesPayload(CurrentStore $current): array
    {
        $store = $current->getOrFail();
        $default = Localization::storeDefault($store);
        $published = Localization::enabled($store);

        // Progress per language: translated fields vs. fields that exist (rough, all types)
        $counts = Translation::selectRaw('locale, COUNT(*) as n')->groupBy('locale')->pluck('n', 'locale');

        return [
            'default' => $default,
            'published' => array_values(array_diff($published, [$default])),
            'languages' => collect(Localization::languages())->map(fn ($name, $code) => [
                'code' => $code,
                'name' => $name,
                'translations' => (int) ($counts[$code] ?? 0),
                'url' => $store->storefrontUrl().($code === $default ? '' : '/'.$code),
            ])->values(),
        ];
    }
}
