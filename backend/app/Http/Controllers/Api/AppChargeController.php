<?php

namespace App\Http\Controllers\Api;

use App\Apps\AppBilling;
use App\Http\Controllers\Controller;
use App\Models\AppCharge;
use App\Models\AppInstallation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Open API for apps: ask the store to approve a charge, and read / cancel your own charges.
 * Only tokens issued to an installed app can use it (store API tokens get 403).
 */
class AppChargeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $install = $this->installation($request);

        return response()->json(['data' => AppCharge::where('app_id', $install->app_id)->where('store_id', $install->store_id)
            ->latest('id')->limit(100)->get()->map->toApi()]);
    }

    public function store(Request $request, AppBilling $billing): JsonResponse
    {
        $install = $this->installation($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0.5', 'max:10000'],
            'interval' => ['nullable', Rule::in(AppCharge::INTERVALS)],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'return_url' => ['required', 'string', 'max:500', 'url', 'regex:#^https?://#i'],
            'test' => ['nullable', 'boolean'],
        ]);
        // The buyer comes back to the app: the return URL must be on one of its redirect hosts
        $hosts = collect([$install->app->app_url, ...($install->app->redirect_uris ?? [])])->map(fn ($u) => parse_url($u, PHP_URL_HOST))->filter()->all();
        abort_unless(in_array(parse_url($data['return_url'], PHP_URL_HOST), $hosts, true), 422, 'return_url must be on your app’s domain.');

        return response()->json(['data' => $billing->create($install, $data)->toApi()], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->charge($request, $id)->toApi()]);
    }

    public function destroy(Request $request, int $id, AppBilling $billing): JsonResponse
    {
        $charge = $this->charge($request, $id);
        $billing->cancel($charge);

        return response()->json(['data' => $charge->fresh()->toApi()]);
    }

    private function charge(Request $request, int $id): AppCharge
    {
        $install = $this->installation($request);

        return AppCharge::where('app_id', $install->app_id)->where('store_id', $install->store_id)->findOrFail($id);
    }

    private function installation(Request $request): AppInstallation
    {
        $token = $request->attributes->get('api_token');
        $install = $token?->app_installation_id ? AppInstallation::withoutGlobalScope('store')->with('app')->find($token->app_installation_id) : null;
        abort_unless($install && $install->active() && $install->app, 403, 'Only installed apps can create charges.');

        return $install;
    }
}
