<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\App;
use App\Webhooks\Webhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Developers: register apps, get credentials, submit for review. Scoped to the signed-in user. */
class PartnerAppController extends Controller
{
    public const MAX_APPS = 10;

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => App::where('user_id', $request->user()->id)->latest('id')->withCount(['installations as installs' => fn ($q) => $q->whereNull('uninstalled_at')])
                ->get()->map(fn (App $a) => $this->present($a)),
            'scopes' => collect(ApiToken::SCOPES)->map(fn ($v, $k) => ['key' => $k, 'label' => __($v[0]), 'description' => __($v[1])])->values(),
            'endpoints' => [
                'authorize' => rtrim((string) config('lumee.admin_url'), '/').'/oauth/authorize',
                'token' => rtrim((string) config('app.url'), '/').'/api/oauth/token',
                'api' => rtrim((string) config('app.url'), '/').'/api/v1',
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (App::where('user_id', $request->user()->id)->count() >= self::MAX_APPS) {
            throw ValidationException::withMessages(['name' => __('每个账号最多创建 :p0 个应用', ['p0' => self::MAX_APPS])]);
        }
        $data = $this->validated($request);
        $app = new App($data);
        $plain = $app->rotateSecret();
        $app->forceFill([
            'user_id' => $request->user()->id, 'slug' => App::uniqueSlug($data['name']),
            'client_id' => Str::lower(Str::random(32)), 'signing_secret' => Str::random(48), 'status' => 'draft',
        ])->save();

        return response()->json(['data' => $this->present($app), 'client_secret' => $plain], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $app = $this->mine($request, $id);
        $data = $this->validated($request);
        $scopesChanged = array_values($data['scopes']) !== array_values($app->scopes ?? []);
        $app->fill($data);
        // Asking for more access needs a new review
        if ($scopesChanged && $app->status === 'listed') {
            $app->status = 'review';
        }
        $app->save();

        return response()->json(['data' => $this->present($app)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $app = $this->mine($request, $id);
        foreach ($app->installations()->whereNull('uninstalled_at')->get() as $install) {
            app(\App\Apps\OAuth::class)->uninstall($install);
        }
        $app->delete();

        return response()->json(['ok' => true]);
    }

    public function rotateSecret(Request $request, int $id): JsonResponse
    {
        $app = $this->mine($request, $id);
        $plain = $app->rotateSecret();
        $app->save();

        return response()->json(['data' => $this->present($app), 'client_secret' => $plain]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $app = $this->mine($request, $id);
        if (! in_array($app->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['app' => __('应用已经在审核中或已上架')]);
        }
        if (! $app->description || ! $app->icon_url) {
            throw ValidationException::withMessages(['app' => __('提交审核前请填写应用介绍并上传图标')]);
        }
        $app->forceFill(['status' => 'review', 'review_note' => null])->save();

        return response()->json(['data' => $this->present($app)]);
    }

    /** Reviews merchants left on one of the developer's apps. */
    public function reviews(Request $request, int $id): JsonResponse
    {
        $app = $this->mine($request, $id);

        return response()->json(['data' => \App\Models\AppReview::with('store:id,name')->where('app_id', $app->id)->latest('updated_at')->limit(200)->get()
            ->map(fn ($r) => ['id' => $r->id, 'rating' => $r->rating, 'body' => $r->body, 'store' => $r->store?->name,
                'developer_reply' => $r->developer_reply, 'updated_at' => $r->updated_at])]);
    }

    /** Public reply under a review (empty text removes it). */
    public function reply(Request $request, int $id, int $reviewId): JsonResponse
    {
        $app = $this->mine($request, $id);
        $data = $request->validate(['reply' => ['nullable', 'string', 'max:2000']]);
        $review = \App\Models\AppReview::where('app_id', $app->id)->findOrFail($reviewId);
        $review->forceFill(['developer_reply' => trim((string) ($data['reply'] ?? '')) ?: null])->saveQuietly();

        return response()->json(['ok' => true, 'developer_reply' => $review->developer_reply]);
    }

    private function mine(Request $request, int $id): App
    {
        return App::where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function validated(Request $request): array
    {
        $url = ['string', 'max:500', 'url'];
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'icon_url' => ['nullable', ...$url, 'regex:#^https://#i'],
            'app_url' => ['required', ...$url],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:5'],
            'redirect_uris.*' => ['required', ...$url],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(array_keys(ApiToken::SCOPES))],
            'uninstall_url' => ['nullable', ...$url],
            'embedded' => ['sometimes', 'boolean'],
            'pricing' => ['nullable', 'string', 'max:80'],
        ], [
            'name.required' => __('请填写应用名称'), 'app_url.required' => __('请填写应用地址'),
            'redirect_uris.required' => __('请至少填写一个回调地址'), 'scopes.required' => __('请至少选择一项权限'),
            'icon_url.regex' => __('图标地址必须以 https:// 开头'),
        ]);
        // Production apps must use https; http is only for http://localhost while developing
        foreach (['app_url' => [$data['app_url']], 'redirect_uris' => $data['redirect_uris']] as $field => $urls) {
            foreach ($urls as $u) {
                if (! preg_match('#^https://#i', $u) && ! preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?(/|$)#i', $u)) {
                    throw ValidationException::withMessages([$field => __('地址必须以 https:// 开头（本地开发可以用 http://localhost）')]);
                }
            }
        }
        if (filled($data['uninstall_url'] ?? null)) {
            try {
                Webhooks::guard($data['uninstall_url']);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['uninstall_url' => $e->getMessage()]);
            }
        }
        $data['redirect_uris'] = array_values(array_unique($data['redirect_uris']));
        $data['scopes'] = array_values(array_intersect(array_keys(ApiToken::SCOPES), $data['scopes']));

        return $data;
    }

    private function present(App $a): array
    {
        return [
            'id' => $a->id, 'name' => $a->name, 'slug' => $a->slug, 'tagline' => $a->tagline, 'description' => $a->description,
            'icon_url' => $a->icon_url, 'app_url' => $a->app_url, 'redirect_uris' => $a->redirect_uris, 'scopes' => $a->scopes,
            'uninstall_url' => $a->uninstall_url, 'client_id' => $a->client_id, 'client_secret_prefix' => $a->client_secret_prefix,
            'signing_secret' => $a->signing_secret, 'status' => $a->status, 'review_note' => $a->review_note,
            'installs' => (int) ($a->installs ?? $a->installations()->whereNull('uninstalled_at')->count()),
            'rating' => (float) $a->rating_avg, 'rating_count' => (int) $a->rating_count,
            'embedded' => (bool) $a->embedded, 'pricing' => $a->pricing,
            'earnings' => [
                'last_30_days' => number_format(\Illuminate\Support\Facades\DB::table('app_charge_bills')->where('app_id', $a->id)->where('created_at', '>=', now()->subDays(30))->sum('developer_share') / 100, 2, '.', ''),
                'total' => number_format(\Illuminate\Support\Facades\DB::table('app_charge_bills')->where('app_id', $a->id)->sum('developer_share') / 100, 2, '.', ''),
                'active_charges' => \App\Models\AppCharge::where('app_id', $a->id)->where('status', 'active')->where('interval', 'every_30_days')->where('test', false)->count(),
            ],
        ];
    }
}
