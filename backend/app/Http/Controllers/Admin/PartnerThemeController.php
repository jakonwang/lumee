<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ThemeListing;
use App\Themes\ThemePackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Developers: themes they publish to the marketplace. A listing is a Lumée theme package (exported
 * from any store's theme library) plus a description and screenshot. Uploading a new package
 * makes a new version; a listed theme goes back to review when its package changes.
 */
class PartnerThemeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ThemeListing::where('user_id', $request->user()->id)->latest('id')->get()->map(fn ($t) => [...$this->present($t),
                'sales' => \App\Models\ThemePurchase::where('theme_listing_id', $t->id)->count(),
                'earnings' => number_format(\App\Models\ThemePurchase::where('theme_listing_id', $t->id)->sum('developer_share') / 100, 2, '.', '')]),
            'prices' => [0, 29, 49, 79, 99, 129, 149, 180, 199, 249, 299, 349],
            'templates' => collect(config('themes'))->map(fn ($t, $k) => ['key' => $k, 'name' => __($t['name'])])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $listing = new ThemeListing($data);
        $listing->price = (int) round(($data['price'] ?? 0) * 100);
        $listing->forceFill(['user_id' => $request->user()->id, 'slug' => ThemeListing::uniqueSlug($listing->name), 'theme' => 'fresh', 'status' => 'draft'])->save();
        $listing->refresh();

        return response()->json(['data' => $this->present($listing)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $listing = $this->mine($request, $id);
        $data = $this->validated($request);
        $listing->fill($data);
        if (array_key_exists('price', $data)) {
            $listing->price = (int) round(($data['price'] ?? 0) * 100);
        }
        $listing->slug = ThemeListing::uniqueSlug($listing->name, $listing->id);
        $listing->save();

        return response()->json(['data' => $this->present($listing)]);
    }

    /** Upload (a new version of) the package. */
    public function upload(Request $request, int $id, ThemePackage $package): JsonResponse
    {
        $listing = $this->mine($request, $id);
        $request->validate(['file' => ['required', 'file', 'max:51200']], ['file.max' => __('文件不能超过 50MB')]);
        $info = $package->inspect($request->file('file'));

        $old = $listing->package_path;
        $version = $listing->version + 1;
        $path = $request->file('file')->storeAs("theme-listings/{$listing->id}", "v{$version}.zip", 'local');
        $listing->forceFill([
            'package_path' => $path, 'version' => $version, 'theme' => $info['theme'], 'swatches' => $info['swatches'],
            'status' => $listing->status === 'listed' ? 'review' : $listing->status,   // changes are reviewed again
        ])->save();
        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        return response()->json(['data' => $this->present($listing)]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $listing = $this->mine($request, $id);
        if (! $listing->package_path) {
            throw ValidationException::withMessages(['file' => __('请先上传主题包')]);
        }
        if (! $listing->preview_url) {
            throw ValidationException::withMessages(['preview_url' => __('请填写主题截图地址，审核和应用市场都会用到')]);
        }
        $listing->forceFill(['status' => 'review', 'review_note' => null])->save();

        return response()->json(['data' => $this->present($listing)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $listing = $this->mine($request, $id);
        $listing->package_path && Storage::disk('local')->delete($listing->package_path);
        $listing->delete();   // installed copies stay in each store's library

        return response()->json(['ok' => true]);
    }

    private function mine(Request $request, int $id): ThemeListing
    {
        return ThemeListing::where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'preview_url' => ['nullable', 'string', 'max:500', 'url', 'regex:#^https://#i'],
            'demo_url' => ['nullable', 'string', 'max:500', 'url', 'regex:#^https?://#i'],
            'price' => ['nullable', 'numeric', 'in:0,29,49,79,99,129,149,180,199,249,299,349'],   // one-time, USD
        ], ['preview_url.regex' => __('截图地址必须是 https 链接')]);
    }

    public static function present(ThemeListing $t): array
    {
        return [
            'id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'tagline' => $t->tagline, 'description' => $t->description,
            'preview_url' => $t->preview_url, 'demo_url' => $t->demo_url, 'theme' => $t->theme, 'template' => __(config("themes.{$t->theme}.name") ?? $t->theme),
            'version' => $t->version, 'has_package' => (bool) $t->package_path, 'swatches' => $t->swatches ?? [],
            'status' => $t->status, 'review_note' => $t->review_note, 'installs' => $t->installs, 'updated_at' => $t->updated_at,
            'price' => number_format(((int) $t->price) / 100, 2, '.', ''),
        ];
    }
}
