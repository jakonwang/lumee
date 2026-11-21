<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\LeadFinder\LeadAi;
use App\LeadFinder\LeadPipeline;
use App\LeadFinder\LeadProfiles;
use App\LeadFinder\LeadScorer;
use App\LeadFinder\LeadSettings;
use App\LeadFinder\RunLeadSearch;
use App\LeadFinder\SafeFetcher;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadSearch;
use App\Support\Csv;
use App\Tenancy\CurrentStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Customers › Lead finder (获客雷达): search for B2B buyers, enrich, score, and work the pipeline. */
class LeadController extends Controller
{
    // ── Setup ──

    public function meta(CurrentStore $current): JsonResponse
    {
        $store = $current->getOrFail();
        $profiles = collect(LeadProfiles::all())->map(fn ($p, $k) => ['key' => $k, 'label' => $p['label'], 'pitch' => $p['pitch'], 'buyers' => $p['buyers']])->values();
        $countries = collect(LeadProfiles::countries())->map(fn ($c, $k) => ['code' => $k, 'name' => $c['zh'], 'en' => $c['en'], 'cities' => $c['cities']])->values();
        $keywords = [];
        foreach (array_keys(LeadProfiles::all()) as $p) {
            foreach (array_keys(LeadProfiles::countries()) as $c) {
                $keywords[$p][$c] = LeadProfiles::keywordsFor($p, $c);
            }
        }

        return response()->json(['profiles' => $profiles, 'countries' => $countries, 'keywords' => $keywords, 'settings' => LeadSettings::public($store),
            'segments' => LeadScorer::SEGMENT_LABELS]);
    }

    public function settings(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'google_key' => ['nullable', 'string', 'max:200'],
            'anthropic_key' => ['nullable', 'string', 'max:300'],
            'ai_auto' => ['boolean'],
            'sender' => ['array'],
            'sender.*' => ['nullable', 'string', 'max:500'],
        ]);
        foreach (['google_key', 'anthropic_key'] as $k) {     // clear_x empties it; blank keeps the saved key
            if ($request->boolean("clear_{$k}")) {
                $data[$k] = '';
            } elseif (blank($data[$k] ?? null)) {
                unset($data[$k]);
            }
        }
        LeadSettings::save($current->getOrFail(), $data);

        return response()->json(['settings' => LeadSettings::public($current->getOrFail()->fresh())]);
    }

    // ── Searches ──

    public function searches(): JsonResponse
    {
        // A worker that died mid-run leaves its search "running" forever: give up after 20 quiet minutes
        LeadSearch::where('status', 'running')->where('updated_at', '<', now()->subMinutes(20))
            ->update(['status' => 'failed', 'stage' => null, 'error' => __('任务中断，请重新搜索'), 'finished_at' => now()]);

        return response()->json(['data' => LeadSearch::latest('id')->limit(50)->get()]);
    }

    public function showSearch(int $id): JsonResponse
    {
        return response()->json(['data' => LeadSearch::findOrFail($id)]);
    }

    public function createSearch(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'industry' => ['required', Rule::in(array_keys(LeadProfiles::all()))],
            'keywords' => ['required', 'array', 'min:1', 'max:8'],
            'keywords.*' => ['required', 'string', 'max:80'],
            'country' => ['required', Rule::in(array_keys(LeadProfiles::countries()))],
            'cities' => ['array', 'max:8'],
            'cities.*' => ['string', 'max:80'],
            'source' => ['required', Rule::in(['google', 'osm'])],
            'limit' => ['nullable', 'integer', 'min:10', 'max:300'],
        ], ['keywords.required' => __('请至少填写一个搜索关键词')]);
        $store = $current->getOrFail();
        if ($data['source'] === 'google' && ! LeadSettings::googleKey($store)) {
            return response()->json(['message' => __('使用 Google 地图需要先在「获客设置」里填写 API Key')], 422);
        }
        if (LeadSearch::whereIn('status', ['queued', 'running'])->count() >= 3) {
            return response()->json(['message' => __('已有 3 个搜索在进行中，请等它们完成后再开始新的搜索')], 422);
        }
        $country = LeadProfiles::countries()[$data['country']];
        $cities = array_values(array_filter(array_map('trim', $data['cities'] ?? [])));
        $search = LeadSearch::create([
            'name' => $country['zh'].($cities ? ' · '.implode('、', array_slice($cities, 0, 3)).(count($cities) > 3 ? '…' : '') : '').' · '.Str::limit($data['keywords'][0], 40),
            'industry' => $data['industry'], 'keywords' => array_values(array_unique($data['keywords'])), 'country' => $data['country'],
            'cities' => $cities, 'source' => $data['source'], 'status' => 'queued',
        ]);
        RunLeadSearch::dispatch($search->id, $data['limit'] ?? 60);

        return response()->json(['data' => $search], 201);
    }

    /** Paste websites → each becomes a lead that gets read and scored. */
    public function importSites(Request $request, CurrentStore $current, LeadPipeline $pipeline): JsonResponse
    {
        $data = $request->validate([
            'websites' => ['required', 'string', 'max:20000'],
            'industry' => ['required', Rule::in(array_keys(LeadProfiles::all()))],
            'country' => ['nullable', Rule::in(array_keys(LeadProfiles::countries()))],
        ]);
        $domains = collect(preg_split('/[\s,;]+/', $data['websites']))->map(fn ($u) => SafeFetcher::domain(trim($u)))
            ->filter(fn ($d) => $d && str_contains($d, '.'))->unique()->take(100)->values();
        if ($domains->isEmpty()) {
            return response()->json(['message' => __('没有识别到网址，每行填一个，例如 example.com')], 422);
        }
        $search = LeadSearch::create(['name' => __('导入网址 · :n 个', ['n' => $domains->count()]), 'industry' => $data['industry'], 'keywords' => [],
            'country' => $data['country'] ?? 'US', 'cities' => [], 'source' => 'manual', 'status' => 'queued', 'found' => $domains->count()]);
        $ids = [];
        $new = 0;
        foreach ($domains as $d) {
            [$lead, $isNew] = $pipeline->upsert(['source' => 'manual', 'source_id' => $d, 'name' => $d, 'category' => null, 'country' => $data['country'] ?? null,
                'city' => null, 'website' => 'https://'.$d], $search);
            $ids[] = $lead->id;
            $new += $isNew ? 1 : 0;
        }
        $search->update(['new_leads' => $new]);
        RunLeadSearch::dispatch($search->id, 0, $ids);

        return response()->json(['data' => $search], 201);
    }

    public function destroySearch(int $id): JsonResponse
    {
        $s = LeadSearch::findOrFail($id);
        if (in_array($s->status, ['queued', 'running'], true)) {
            $s->update(['status' => 'failed', 'error' => __('已取消')]);
        } else {
            $s->delete();   // its leads stay (lead_search_id → null)
        }

        return response()->json(null, 204);
    }

    // ── Leads ──

    public function index(Request $request): JsonResponse
    {
        $page = $this->filtered($request)->paginate(min(100, (int) $request->query('per_page', 25)));
        $page->getCollection()->transform(fn (Lead $l) => $this->row($l));

        return response()->json([...$page->toArray(), 'stats' => $this->stats()]);
    }

    public function show(int $id): JsonResponse
    {
        $lead = Lead::with('search')->findOrFail($id);

        return response()->json(['data' => [...$this->row($lead),
            'address' => $lead->address, 'phones' => $lead->phones ?? [], 'lat' => $lead->lat, 'lng' => $lead->lng, 'signals' => $lead->signals ?? [],
            'enrich_error' => $lead->enrich_error, 'note' => $lead->note, 'search' => $lead->search?->only(['id', 'name', 'industry']),
            'has_site_text' => (bool) $lead->site_text,
            'activities' => $lead->activities()->latest('id')->limit(50)->get(['id', 'type', 'body', 'created_at']),
        ]]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $lead = Lead::findOrFail($id);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Lead::STATUSES)],
            'starred' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'name' => ['sometimes', 'string', 'max:200'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:60'],
            'emails' => ['sometimes', 'array', 'max:10'],
            'emails.*' => ['email', 'max:120'],
            'segment' => ['sometimes', Rule::in(array_keys(LeadScorer::SEGMENT_LABELS))],
        ]);
        if (isset($data['status']) && $data['status'] !== $lead->status) {
            $this->log($lead, 'status', $data['status']);
            if ($data['status'] === 'contacted' && ! $lead->contacted_at) {
                $data['contacted_at'] = now();
            }
        }
        $lead->update($data);

        return $this->show($id);
    }

    public function addActivity(Request $request, int $id): JsonResponse
    {
        $lead = Lead::findOrFail($id);
        $data = $request->validate(['type' => ['required', Rule::in(['note', 'email', 'call', 'whatsapp'])], 'body' => ['nullable', 'string', 'max:5000']]);
        $this->log($lead, $data['type'], $data['body'] ?? null);
        if (in_array($data['type'], ['email', 'call', 'whatsapp'], true) && $lead->status === 'new') {
            $lead->update(['status' => 'contacted', 'contacted_at' => now()]);
            $this->log($lead, 'status', 'contacted');
        }

        return $this->show($id);
    }

    public function bulk(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['status', 'star', 'unstar', 'delete', 'recheck', 'analyze'])],
            'status' => ['required_if:action,status', Rule::in(Lead::STATUSES)],
        ]);
        $q = Lead::whereIn('id', $data['ids']);
        switch ($data['action']) {
            case 'status':
                foreach ($q->get() as $lead) {
                    if ($lead->status !== $data['status']) {
                        $this->log($lead, 'status', $data['status']);
                        $lead->update(['status' => $data['status'], 'contacted_at' => $data['status'] === 'contacted' ? ($lead->contacted_at ?? now()) : $lead->contacted_at]);
                    }
                }
                break;
            case 'star':
            case 'unstar':
                $q->update(['starred' => $data['action'] === 'star']);
                break;
            case 'delete':
                $q->delete();
                break;
            case 'recheck':
            case 'analyze':
                if ($data['action'] === 'analyze' && ! LeadAi::ready($current->getOrFail())) {
                    return response()->json(['message' => __('AI 分析需要先在「获客设置」里填写 Claude API Key')], 422);
                }
                $ids = $q->pluck('id')->all();
                $first = Lead::find($ids[0]);
                $search = LeadSearch::create(['name' => ($data['action'] === 'analyze' ? __('AI 分析') : __('重新抓取官网')).' · '.__(':n 条线索', ['n' => count($ids)]),
                    'industry' => $first?->search?->industry ?? 'custom', 'keywords' => $first?->search?->keywords ?? [], 'country' => $first?->country ?? 'US',
                    'cities' => [], 'source' => 'manual', 'status' => 'queued', 'found' => count($ids)]);
                RunLeadSearch::dispatch($search->id, 0, $ids, $data['action'] === 'analyze');

                return response()->json(['search' => $search, 'stats' => $this->stats()]);
        }

        return response()->json(['stats' => $this->stats()]);
    }

    /** Re-read one lead's website and re-score it now (with AI when available). */
    public function recheck(int $id, CurrentStore $current, LeadPipeline $pipeline): JsonResponse
    {
        $lead = Lead::with('search')->findOrFail($id);
        $terms = $lead->search?->industry === 'custom' ? ($lead->search->keywords ?? []) : [];
        $pipeline->enrich($lead);
        $pipeline->score($lead, $terms);

        return $this->show($id);
    }

    public function analyze(int $id, CurrentStore $current, LeadPipeline $pipeline): JsonResponse
    {
        $lead = Lead::with('search')->findOrFail($id);
        try {
            if ($lead->website && ! $lead->site_text && $lead->enrich_status !== 'done') {
                $pipeline->enrich($lead);
            }
            $pipeline->analyze($current->getOrFail(), $lead, $lead->search?->industry === 'custom' ? ($lead->search->keywords ?? []) : []);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->show($id);
    }

    public function outreach(Request $request, int $id, CurrentStore $current, LeadAi $ai): JsonResponse
    {
        $lead = Lead::with('search')->findOrFail($id);
        $data = $request->validate(['language' => ['nullable', 'string', 'max:5']]);
        $store = $current->getOrFail();
        if (! LeadAi::ready($store)) {
            return response()->json(['data' => [...LeadAi::templateOutreach($store, $lead), 'ai' => false]]);
        }
        try {
            return response()->json(['data' => [...$ai->outreach($store, $lead, $data['language'] ?? null), 'ai' => true]]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Turn a lead into a B2B company (Customers › Companies), with its email as the first buyer. */
    public function convert(int $id): JsonResponse
    {
        $lead = Lead::findOrFail($id);
        if ($lead->company_id && Company::find($lead->company_id)) {
            return response()->json(['company_id' => $lead->company_id]);
        }
        $company = DB::transaction(function () use ($lead) {
            $company = Company::create([
                'name' => Str::limit($lead->name, 150, ''), 'tag' => 'wholesale',
                'locations' => $lead->address ? [['name' => $lead->name, 'address1' => Str::limit($lead->address, 200, ''), 'city' => $lead->city ?? '',
                    'country' => $lead->country ?? '', 'phone' => $lead->phone]] : [],
                'note' => trim(implode("\n", array_filter([__('来自获客雷达'), $lead->website, $lead->summary, $lead->note]))),
            ]);
            if ($email = ($lead->emails ?? [])[0] ?? null) {
                $m = Customer::firstOrNew(['email' => Str::lower($email)]);
                if (! $m->exists || ! $m->company_id) {
                    $m->forceFill(['company_id' => $company->id, 'company_role' => 'admin', 'phone' => $m->phone ?? $lead->phone])->save();
                }
            }
            $lead->update(['company_id' => $company->id, 'status' => in_array($lead->status, ['new', 'contacted', 'replied'], true) ? 'qualified' : $lead->status]);
            $this->log($lead, 'status', 'qualified');

            return $company;
        });

        return response()->json(['company_id' => $company->id], 201);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filtered($request)->limit(5000)->get();
        $seg = LeadScorer::SEGMENT_LABELS;

        return Csv::download('leads-'.now()->format('Ymd-His').'.csv',
            ['公司', '评分', '客户类型', '国家', '城市', '地址', '电话', '邮箱', '官网', 'WhatsApp', 'Facebook', 'Instagram', 'LinkedIn', '评分(Google)', '评价数', '状态', '简介', '备注'],
            $rows->map(fn (Lead $l) => [$l->name, $l->score, $seg[$l->segment] ?? $l->segment, $l->country, $l->city, $l->address, $l->phone,
                implode('; ', $l->emails ?? []), $l->website, $l->socials['whatsapp'] ?? '', $l->socials['facebook'] ?? '', $l->socials['instagram'] ?? '',
                $l->socials['linkedin'] ?? '', $l->rating, $l->reviews_count, $l->status, $l->summary, $l->note]));
    }

    // ── Helpers ──

    private function filtered(Request $request): Builder
    {
        $like = fn (string $t) => '%'.addcslashes($t, '%_\\').'%';

        return Lead::query()
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', $like($t))->orWhere('domain', 'like', $like($t))
                ->orWhere('city', 'like', $like($t))->orWhere('emails', 'like', $like($t))))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('segment'), fn ($q, $s) => $q->whereIn('segment', explode(',', $s)))
            ->when($request->query('grade'), fn ($q, $g) => match ($g) {
                'A' => $q->where('score', '>=', 75), 'B' => $q->whereBetween('score', [55, 74]), 'C' => $q->whereBetween('score', [35, 54]), default => $q->where('score', '<', 35),
            })
            ->when($request->query('min_score'), fn ($q, $s) => $q->where('score', '>=', (int) $s))
            ->when($request->boolean('has_email'), fn ($q) => $q->whereNotNull('emails')->whereRaw('JSON_LENGTH(emails) > 0'))
            ->when($request->boolean('has_phone'), fn ($q) => $q->whereNotNull('phone'))
            ->when($request->boolean('starred'), fn ($q) => $q->where('starred', true))
            ->when($request->query('country'), fn ($q, $c) => $q->where('country', $c))
            ->when($request->query('search_id'), fn ($q, $id) => $q->where('lead_search_id', (int) $id))
            ->when($request->query('ids'), fn ($q, $ids) => $q->whereIn('id', array_map('intval', explode(',', $ids))))
            ->when($request->query('sort', 'score'), fn ($q, $s) => match ($s) {
                'newest' => $q->latest('id'), 'name' => $q->orderBy('name'), 'reviews' => $q->orderByDesc('reviews_count')->orderByDesc('score'),
                default => $q->orderByDesc('score')->orderByDesc('id'),
            });
    }

    private function row(Lead $l): array
    {
        return ['id' => $l->id, 'name' => $l->name, 'category' => $l->category, 'segment' => $l->segment, 'country' => $l->country, 'city' => $l->city,
            'phone' => $l->phone, 'website' => $l->website, 'domain' => $l->domain, 'emails' => $l->emails ?? [], 'socials' => (object) ($l->socials ?? []),
            'summary' => $l->summary, 'rating' => $l->rating, 'reviews_count' => $l->reviews_count, 'score' => $l->score, 'score_reasons' => $l->score_reasons ?? [],
            'ai_scored' => $l->ai_scored, 'status' => $l->status, 'starred' => $l->starred, 'enrich_status' => $l->enrich_status, 'source' => $l->source,
            'company_id' => $l->company_id, 'contacted_at' => $l->contacted_at, 'created_at' => $l->created_at];
    }

    private function stats(): array
    {
        $s = Lead::query()->selectRaw("COUNT(*) total,
            SUM(CASE WHEN emails IS NOT NULL AND JSON_LENGTH(emails) > 0 THEN 1 ELSE 0 END) with_email,
            SUM(CASE WHEN phone IS NOT NULL THEN 1 ELSE 0 END) with_phone,
            SUM(CASE WHEN score >= 75 THEN 1 ELSE 0 END) grade_a,
            SUM(CASE WHEN status IN ('contacted','replied','qualified','won') THEN 1 ELSE 0 END) contacted,
            SUM(CASE WHEN status IN ('replied','qualified','won') THEN 1 ELSE 0 END) replied,
            SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) won,
            SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) this_week", [now()->subDays(7)])->first();

        return array_map('intval', $s->toArray());
    }

    private function log(Lead $lead, string $type, ?string $body): void
    {
        $lead->activities()->create(['type' => $type, 'body' => $body, 'user_id' => auth()->id()]);
    }
}
