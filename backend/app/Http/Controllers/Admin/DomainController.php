<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\DnsChecker;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Custom domains. Flow: add → merchant sets a CNAME → verify (DNS check) → optionally make primary.
 * Unverified domains never serve the storefront (ResolveStorefrontStore only matches verified ones),
 * so nobody can "claim" a domain they don't control.
 */
class DomainController extends Controller
{
    public function index(CurrentStore $current): JsonResponse
    {
        return response()->json($this->payload($current));
    }

    public function store(Request $request, CurrentStore $current): JsonResponse
    {
        $request->validate(['host' => ['required', 'string', 'max:253']], ['host.required' => __('请输入域名')]);
        if (! app(\App\Billing\Billing::class)->limits($current->getOrFail())['custom_domains']) {
            throw ValidationException::withMessages(['host' => __('当前套餐不支持绑定自有域名，请升级到基础版或以上')]);
        }
        $host = $this->normalize($request->input('host'));

        $platform = '.'.config('lumee.storefront_domain');
        $valid = preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', $host)
            && ! filter_var($host, FILTER_VALIDATE_IP);
        if (! $valid) {
            throw ValidationException::withMessages(['host' => __('域名格式不正确，例如 shop.yourbrand.com')]);
        }
        if (str_ends_with($host, $platform) || $host === ltrim($platform, '.')) {
            throw ValidationException::withMessages(['host' => __('这是平台提供的免费域名，不需要添加')]);
        }
        // Global check (any store): one domain belongs to one store
        if (Domain::where('host', $host)->exists()) {
            throw ValidationException::withMessages(['host' => __('这个域名已被添加')]);
        }
        if ($current->getOrFail()->domains()->where('type', 'custom')->count() >= 5) {
            throw ValidationException::withMessages(['host' => __('每个店铺最多绑定 5 个自定义域名')]);
        }

        $current->getOrFail()->domains()->create(['host' => $host, 'type' => 'custom', 'is_primary' => false]);

        return response()->json($this->payload($current), 201);
    }

    public function verify(int $id, CurrentStore $current, DnsChecker $dns): JsonResponse
    {
        $domain = $this->find($id, $current);
        $result = $dns->check($domain->host);

        if ($result['ok']) {
            $domain->update(['verified_at' => $domain->verified_at ?? now()]);
        }

        return response()->json([
            ...$this->payload($current),
            'verified' => $result['ok'],
            'message' => $result['ok']
                ? __('验证成功，域名已生效')
                : __('还没有检测到正确的 DNS 记录').($result['found'] ? __('（当前：').implode(__('，'), $result['found']).__('）') : '').__('。DNS 生效通常需要几分钟到 48 小时。'),
        ]);
    }

    public function makePrimary(int $id, CurrentStore $current): JsonResponse
    {
        $domain = $this->find($id, $current);
        if (! $domain->verified_at) {
            throw ValidationException::withMessages(['domain' => __('请先完成域名验证')]);
        }

        DB::transaction(function () use ($domain, $current) {
            $current->getOrFail()->domains()->update(['is_primary' => false]);
            $domain->update(['is_primary' => true]);
        });

        return response()->json($this->payload($current));
    }

    public function destroy(int $id, CurrentStore $current): JsonResponse
    {
        $domain = $this->find($id, $current);
        if ($domain->type === 'platform') {
            throw ValidationException::withMessages(['domain' => __('平台免费域名不能删除')]);
        }

        DB::transaction(function () use ($domain, $current) {
            $wasPrimary = $domain->is_primary;
            $domain->delete();
            if ($wasPrimary) {
                // Fall back to the free platform domain
                $current->getOrFail()->domains()->where('type', 'platform')->update(['is_primary' => true]);
            }
        });

        return response()->json($this->payload($current));
    }

    private function find(int $id, CurrentStore $current): Domain
    {
        // Domains aren't globally scoped by the tenancy trait, so scope explicitly
        return $current->getOrFail()->domains()->findOrFail($id);
    }

    private function normalize(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('~^[a-z]+://~', '', $host);    // strip scheme
        $host = preg_replace('~[/?#:].*$~', '', $host);     // strip path, query, port

        return rtrim($host, '.');
    }

    private function payload(CurrentStore $current): array
    {
        $store = $current->getOrFail()->fresh('domains');

        return [
            'data' => $store->domains->sortByDesc('is_primary')->values()->map(fn (Domain $d) => [
                'id' => $d->id,
                'host' => $d->host,
                'type' => $d->type,
                'is_primary' => $d->is_primary,
                'verified' => $d->verified_at !== null,
                'verified_at' => $d->verified_at,
            ]),
            'storefront_url' => $store->storefrontUrl(),
            'dns' => [
                'cname_target' => config('lumee.cname_target'),
                'server_ip' => config('lumee.server_ip'),
            ],
        ];
    }
}
