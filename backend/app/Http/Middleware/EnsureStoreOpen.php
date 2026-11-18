<?php

namespace App\Http\Middleware;

use App\Billing\Billing;
use App\Tenancy\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin API: a store whose trial ended (or subscription lapsed / was suspended) can only
 * reach the billing endpoints. 402 + code lets the admin app send the user to the billing page.
 */
class EnsureStoreOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $store = app(CurrentStore::class)->getOrFail();
        $state = app(Billing::class)->state($store);
        if (! in_array($state, Billing::OPEN, true)) {
            return response()->json([
                'message' => $state === 'suspended' ? __('店铺已被平台暂停，请联系 Lumée 客服') : __('店铺试用已结束或套餐已到期，请选择套餐后继续使用'),
                'code' => 'billing_required',
                'state' => $state,
            ], 402);
        }

        return $next($request);
    }
}
