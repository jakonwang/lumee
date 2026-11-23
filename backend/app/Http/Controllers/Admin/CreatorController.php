<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\LeadFinder\LeadAi;
use App\LeadFinder\LeadProfiles;
use App\LeadFinder\LeadSettings;
use App\LeadFinder\ProfileEnricher;
use App\LeadFinder\Sources\WebSearchSource;
use App\Marketing\Affiliates;
use App\Marketing\Creators;
use App\Models\Creator;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Marketing › Creators (达人合作): search, score, contact, turn into affiliates. */
class CreatorController extends Controller
{
    public function index(Request $request, CurrentStore $current): JsonResponse
    {
        $store = $current->getOrFail();
        $list = Creator::with('affiliate:id,code,status')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('platform'), fn ($q, $p) => $q->where('platform', $p))
            ->when($request->query('country'), fn ($q, $c) => $q->where('country', $c))
            ->orderByDesc('score')->orderByDesc('id')->paginate(50);

        return response()->json([
            'data' => collect($list->items())->map(fn ($c) => $this->row($c)),
            'meta' => ['total' => $list->total(), 'page' => $list->currentPage(), 'last_page' => $list->lastPage()],
            'counts' => Creator::selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
            'countries' => collect(LeadProfiles::countries())->map(fn ($c, $k) => ['code' => $k, 'name' => $c['zh']])->values(),
            'ready' => ['serp' => LeadSettings::serpReady($store), 'instagram' => ProfileEnricher::instagramReady($store), 'ai' => LeadAi::ready($store)],
            'default_rate' => Affiliates::settings($store)['default_rate'],
        ]);
    }

    public function search(Request $request, CurrentStore $current, Creators $creators): JsonResponse
    {
        $data = $request->validate([
            'keywords' => ['required', 'array', 'min:1', 'max:3'],
            'keywords.*' => ['required', 'string', 'max:80'],
            'country' => ['required', Rule::in(array_keys(LeadProfiles::countries()))],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => [Rule::in(WebSearchSource::CREATOR_CHANNELS)],
        ]);
        $store = $current->getOrFail();
        if (! LeadSettings::serpReady($store)) {
            throw ValidationException::withMessages(['keywords' => __('找达人需要先在「获客雷达 › 设置」里填写 SerpApi Key')]);
        }
        try {
            $r = $creators->search($store, array_values(array_unique(array_map('trim', $data['keywords']))), $data['country'], $data['platforms']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['keywords' => $e->getMessage()]);
        }

        return response()->json($r);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $c = Creator::findOrFail($id);
        $data = $request->validate(['status' => ['sometimes', Rule::in(Creator::STATUSES)], 'note' => ['nullable', 'string', 'max:1000']]);
        if (($data['status'] ?? null) === 'contacted' && ! $c->contacted_at) {
            $c->contacted_at = now();
        }
        $c->fill($data)->save();

        return response()->json(['data' => $this->row($c->fresh('affiliate'))]);
    }

    public function outreach(int $id, CurrentStore $current, Creators $creators): JsonResponse
    {
        return response()->json(['data' => $creators->outreach($current->getOrFail(), Creator::findOrFail($id))]);
    }

    public function affiliate(Request $request, int $id, CurrentStore $current, Creators $creators): JsonResponse
    {
        $c = Creator::findOrFail($id);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['nullable', 'string', 'min:2', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:90'],
        ], ['code.regex' => __('推广码只能包含字母、数字、- 和 _')]);
        try {
            $creators->toAffiliate($current->getOrFail(), $c, $data['email'], $data['code'] ?? null, isset($data['commission_rate']) ? (float) $data['commission_rate'] : null);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->row($c->fresh('affiliate'))], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        Creator::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    private function row(Creator $c): array
    {
        $store = app(CurrentStore::class)->getOrFail();

        return [...$c->only('id', 'platform', 'handle', 'url', 'name', 'country', 'niche', 'followers', 'whatsapp', 'website', 'bio', 'score', 'status', 'note', 'contacted_at', 'created_at'),
            'emails' => $c->emails ?? [], 'score_reasons' => $c->score_reasons ?? [],
            'affiliate' => $c->affiliate ? ['id' => $c->affiliate->id, 'code' => $c->affiliate->code, 'link' => $store->storefrontUrl().'/?ref='.$c->affiliate->code] : null,
            'suggested_code' => $c->affiliate ? null : Creators::suggestCode($c)];
    }
}
