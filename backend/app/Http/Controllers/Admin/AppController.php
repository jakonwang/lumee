<?php

namespace App\Http\Controllers\Admin;

use App\Apps\OAuth;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\AppReview;
use App\Support\Tr;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Merchant side: app store, installed apps, the OAuth consent screen and uninstall. */
class AppController extends Controller
{
    public function index(CurrentStore $current): JsonResponse
    {
        $installed = AppInstallation::with('app.developer:id,name')->whereNull('uninstalled_at')->latest('installed_at')->get();
        $installedIds = $installed->pluck('app_id')->all();

        return response()->json([
            'installed' => $installed->filter(fn ($i) => $i->app)->map(fn (AppInstallation $i) => [
                'id' => $i->id, 'app' => $this->card($i->app), 'scopes' => $i->scopes, 'installed_at' => $i->installed_at,
                'open_url' => $this->openUrl($i->app, $current), 'embedded' => (bool) $i->app->embedded,
                'charges' => \App\Models\AppCharge::where('app_id', $i->app_id)->where('store_id', $current->id())->where('status', 'active')
                    ->where('interval', 'every_30_days')->get()->map(fn ($c) => $this->charge($c))->values(),
            ])->values(),
            'monthly_app_spend' => number_format(\App\Models\AppCharge::where('store_id', $current->id())->where('status', 'active')
                ->where('interval', 'every_30_days')->where('test', false)->sum('price') / 100, 2, '.', ''),
            'store' => App::with('developer:id,name')->where('status', 'listed')->orderBy('name')->get()
                ->map(fn (App $a) => [...$this->card($a), 'installed' => in_array($a->id, $installedIds, true), 'install_url' => $this->openUrl($a, $current)]),
            'scopes' => collect(Tr::deep(ApiToken::SCOPES))->map(fn ($v, $k) => ['key' => $k, 'label' => $v[0], 'description' => $v[1]])->values(),
        ]);
    }

    /** An app's reviews, newest first, plus this store's own review (if any). */
    public function reviews(int $id, CurrentStore $current): JsonResponse
    {
        $app = App::where('status', 'listed')->findOrFail($id);
        $reviews = AppReview::with('store:id,name')->where('app_id', $app->id)->latest('updated_at')->limit(100)->get();
        $mine = $reviews->firstWhere('store_id', $current->id());

        return response()->json([
            'app' => $this->card($app),
            'reviews' => $reviews->map(fn (AppReview $r) => $this->review($r))->values(),
            'mine' => $mine ? $this->review($mine) : null,
            'can_review' => $this->installedEver($app->id),
            'distribution' => collect([5, 4, 3, 2, 1])->map(fn ($n) => ['stars' => $n, 'count' => $reviews->where('rating', $n)->count()])->values(),
        ]);
    }

    /** Write or update this store's review. Only stores that have installed the app may review it. */
    public function saveReview(Request $request, int $id, CurrentStore $current): JsonResponse
    {
        $app = App::where('status', 'listed')->findOrFail($id);
        abort_unless($this->installedEver($app->id), 422, __('安装过这个应用的店铺才能评价'));
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'body' => ['nullable', 'string', 'max:2000']]);
        $review = AppReview::updateOrCreate(
            ['app_id' => $app->id, 'store_id' => $current->id()],
            ['user_id' => $request->user()->id, 'rating' => $data['rating'], 'body' => trim((string) ($data['body'] ?? '')) ?: null],
        );

        return response()->json(['data' => $this->review($review->load('store:id,name')), 'app' => $this->card($app->fresh())]);
    }

    public function deleteReview(int $id, CurrentStore $current): JsonResponse
    {
        AppReview::where('app_id', $id)->where('store_id', $current->id())->first()?->delete();

        return response()->json(['ok' => true]);
    }

    private function installedEver(int $appId): bool
    {
        return AppInstallation::where('app_id', $appId)->exists();   // store-scoped; uninstalled stores may still review
    }

    private function review(AppReview $r): array
    {
        return [
            'id' => $r->id, 'rating' => $r->rating, 'body' => $r->body, 'store' => $r->store?->name,
            'developer_reply' => $r->developer_reply, 'updated_at' => $r->updated_at,
        ];
    }

    /** Charge confirmation page: what the app is asking for. */
    public function chargeInfo(string $token, CurrentStore $current): JsonResponse
    {
        $charge = $this->pendingCharge($token, $current);

        return response()->json(['data' => [...$this->charge($charge), 'app' => $this->card($charge->app->load('developer:id,name')),
            'trial_available' => $charge->trial_days > 0 && ! \App\Models\AppCharge::where('app_id', $charge->app_id)->where('store_id', $charge->store_id)
                ->where('interval', 'every_30_days')->whereNotNull('activated_at')->exists()]]);
    }

    public function approveCharge(string $token, CurrentStore $current, \App\Apps\AppBilling $billing): JsonResponse
    {
        $charge = $billing->approve($this->pendingCharge($token, $current));

        return response()->json(['redirect_url' => $this->returnUrl($charge)]);
    }

    public function declineCharge(string $token, CurrentStore $current, \App\Apps\AppBilling $billing): JsonResponse
    {
        $charge = $this->pendingCharge($token, $current);
        $billing->decline($charge);

        return response()->json(['redirect_url' => $this->returnUrl($charge)]);
    }

    /** App Bridge: a fresh session token + the URL to load in the admin iframe. */
    public function session(int $id, Request $request, CurrentStore $current): JsonResponse
    {
        $install = AppInstallation::with('app')->whereNull('uninstalled_at')->findOrFail($id);   // store-scoped
        abort_unless($install->app, 404);
        $store = $current->getOrFail();
        $token = \App\Apps\SessionToken::issue($install->app, $store, $request->user());
        $url = $install->app->app_url.(str_contains($install->app->app_url, '?') ? '&' : '?').http_build_query([
            'store_id' => $store->id, 'store' => $store->handle, 'embedded' => 1, 'host' => rtrim((string) config('lumee.admin_url'), '/'), 'session' => $token,
        ]);
        $parts = parse_url($install->app->app_url);

        return response()->json(['token' => $token, 'url' => $url, 'origin' => ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : ''),
            'app' => $this->card($install->app), 'expires_in' => \App\Apps\SessionToken::TTL]);
    }

    private function pendingCharge(string $token, CurrentStore $current): \App\Models\AppCharge
    {
        $charge = \App\Models\AppCharge::with('app')->where('token', $token)->where('store_id', $current->id())->firstOrFail();
        abort_unless($charge->app, 404);
        abort_if($charge->status !== 'pending', 422, __('这笔收费已经处理过了'));

        return $charge;
    }

    private function returnUrl(\App\Models\AppCharge $c): string
    {
        return $c->return_url.(str_contains($c->return_url, '?') ? '&' : '?').http_build_query(['charge_id' => $c->id]);
    }

    private function charge(\App\Models\AppCharge $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'price' => number_format($c->price / 100, 2, '.', ''), 'interval' => $c->interval,
            'trial_days' => $c->trial_days, 'test' => $c->test, 'status' => $c->status, 'trial_ends_at' => $c->trial_ends_at, 'billing_on' => $c->billing_on,
        ];
    }

    /** Consent screen data for /oauth/authorize. */
    public function authorizeInfo(Request $request, OAuth $oauth, CurrentStore $current): JsonResponse
    {
        ['app' => $app, 'scopes' => $scopes] = $oauth->check($request->query(), $request->user());
        $install = AppInstallation::where('app_id', $app->id)->whereNull('uninstalled_at')->first();

        return response()->json([
            'app' => $this->card($app->load('developer:id,name')),
            'scopes' => collect($scopes)->map(fn ($s) => ['key' => $s, 'label' => __(ApiToken::SCOPES[$s][0]), 'description' => __(ApiToken::SCOPES[$s][1])])->values(),
            'store' => ['id' => $current->id(), 'name' => $current->getOrFail()->name],
            'reinstall' => (bool) $install,
            'listed' => $app->status === 'listed',
        ]);
    }

    public function approve(Request $request, OAuth $oauth, CurrentStore $current): JsonResponse
    {
        return response()->json(['redirect_url' => $oauth->approve($request->all(), $request->user(), $current->getOrFail())]);
    }

    public function uninstall(int $id, OAuth $oauth): JsonResponse
    {
        $oauth->uninstall(AppInstallation::findOrFail($id));   // store-scoped

        return response()->json(['ok' => true]);
    }

    private function card(App $a): array
    {
        return [
            'id' => $a->id, 'name' => $a->name, 'slug' => $a->slug, 'tagline' => $a->tagline, 'description' => $a->description,
            'icon_url' => $a->icon_url, 'developer' => $a->developer?->name, 'scopes' => $a->scopes,
            'rating' => (float) $a->rating_avg, 'rating_count' => (int) $a->rating_count,
            'pricing' => $a->pricing, 'embedded' => (bool) $a->embedded,
        ];
    }

    /** The app's own page, told which store it's being opened for. */
    private function openUrl(App $a, CurrentStore $current): string
    {
        $store = $current->getOrFail();

        return $a->app_url.(str_contains($a->app_url, '?') ? '&' : '?').http_build_query(['store_id' => $store->id, 'store' => $store->handle]);
    }
}
