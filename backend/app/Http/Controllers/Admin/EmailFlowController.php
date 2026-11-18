<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Marketing\Flows;
use App\Marketing\SendQuota;
use App\Models\Discount;
use App\Models\EmailCampaign;
use App\Models\EmailFlow;
use App\Models\EmailFlowEnrollment;
use App\Models\Product;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Automated email flows: list (with stats), edit settings and steps. Step preview / test use the campaign endpoints. */
class EmailFlowController extends Controller
{
    public const WIN_BACK_DAYS = [30, 60, 90, 180];

    public function index(CurrentStore $current, Flows $flows, SendQuota $quota): JsonResponse
    {
        $store = $current->getOrFail();
        $flows->ensureDefaults($store);

        return response()->json([
            'data' => EmailFlow::with('steps')->orderBy('id')->get()->map(fn (EmailFlow $f) => $this->present($f)),
            'quota' => $quota->summary($store),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->present(EmailFlow::with('steps')->findOrFail($id), true)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $flow = EmailFlow::findOrFail($id);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'stop_on_order' => ['nullable', 'boolean'],
            'win_back_days' => ['nullable', 'integer', Rule::in(self::WIN_BACK_DAYS)],
            'browse_hours' => ['nullable', 'integer', Rule::in([1, 2, 4, 8, 24])],
            'steps' => ['required', 'array', 'min:1', 'max:'.Flows::MAX_STEPS],
            'steps.*.id' => ['nullable', 'integer'],
            'steps.*.delay_hours' => ['required', 'integer', 'min:0', 'max:2160'],
            'steps.*.subject' => ['required', 'string', 'max:150'],
            'steps.*.preheader' => ['nullable', 'string', 'max:150'],
            'steps.*.heading' => ['nullable', 'string', 'max:150'],
            'steps.*.body' => ['nullable', 'string', 'max:50000'],
            'steps.*.button_label' => ['nullable', 'string', 'max:60'],
            'steps.*.button_url' => ['nullable', 'string', 'max:500', 'regex:#^(https?://|/)#i'],
            'steps.*.product_ids' => ['nullable', 'array', 'max:4'],
            'steps.*.product_ids.*' => ['integer'],
            'steps.*.discount_code' => ['nullable', 'string', 'max:40'],
        ], [
            'steps.*.subject.required' => __('每封邮件都要填写主题'),
            'steps.*.button_url.regex' => __('按钮链接要以 http(s):// 或 / 开头'),
            'steps.max' => __('每个自动化最多 5 封邮件'),
        ]);

        foreach ($data['steps'] as $i => $s) {
            if (filled($s['discount_code'] ?? null) && ! Discount::where('code', Discount::normalizeCode($s['discount_code']))->exists()) {
                throw ValidationException::withMessages(["steps.$i.discount_code" => __('找不到这个折扣码，请先在「折扣」里创建')]);
            }
        }

        DB::transaction(function () use ($flow, $data) {
            $flow->enabled = (bool) $data['enabled'];
            $flow->settings = [
                'stop_on_order' => (bool) ($data['stop_on_order'] ?? $flow->setting('stop_on_order')),
                'win_back_days' => (int) ($data['win_back_days'] ?? $flow->setting('win_back_days')),
                'browse_hours' => (int) ($data['browse_hours'] ?? $flow->setting('browse_hours')),
            ];
            $flow->save();

            $keep = [];
            foreach (array_values($data['steps']) as $i => $s) {
                $step = filled($s['id'] ?? null) ? $flow->steps()->whereKey($s['id'])->first() : null;
                $step ??= new EmailCampaign(['audience' => 'subscribers', 'status' => 'flow']);
                $ids = array_values(array_unique(array_map('intval', $s['product_ids'] ?? [])));
                $found = Product::whereIn('id', $ids)->pluck('id')->all();
                $step->fill([
                    'subject' => $s['subject'], 'preheader' => $s['preheader'] ?? null, 'heading' => $s['heading'] ?? null, 'body' => $s['body'] ?? null,
                    'button_label' => $s['button_label'] ?? null, 'button_url' => $s['button_url'] ?? null,
                    'product_ids' => array_values(array_filter($ids, fn ($x) => in_array($x, $found, true))) ?: null,
                    'discount_code' => filled($s['discount_code'] ?? null) ? Discount::normalizeCode($s['discount_code']) : null,
                    'delay_hours' => (int) $s['delay_hours'],
                ]);
                $step->forceFill(['email_flow_id' => $flow->id, 'flow_position' => $i + 1, 'status' => 'flow'])->save();
                $keep[] = $step->id;
            }
            $flow->steps()->whereNotIn('id', $keep)->delete();
        });

        return response()->json(['data' => $this->present($flow->fresh('steps'), true)]);
    }

    private function present(EmailFlow $f, bool $detail = false): array
    {
        $enroll = EmailFlowEnrollment::where('email_flow_id', $f->id)->select('status', DB::raw('COUNT(*) as n'))->groupBy('status')->pluck('n', 'status');
        $sent = (int) $f->steps->sum('sent_count');

        return [
            'id' => $f->id,
            'type' => $f->type,
            'label' => __(EmailFlow::TYPES[$f->type][0] ?? $f->type),
            'description' => __(EmailFlow::TYPES[$f->type][1] ?? ''),
            'enabled' => $f->enabled,
            'stop_on_order' => (bool) $f->setting('stop_on_order'),
            'win_back_days' => (int) $f->setting('win_back_days'),
            'browse_hours' => (int) $f->setting('browse_hours'),
            'stats' => [
                'active' => (int) ($enroll['active'] ?? 0),
                'completed' => (int) ($enroll['completed'] ?? 0),
                'sent' => $sent,
                'open_rate' => $sent ? round($f->steps->sum('opened_count') / $sent * 100, 1) : 0,
                'click_rate' => $sent ? round($f->steps->sum('clicked_count') / $sent * 100, 1) : 0,
            ],
            'steps' => $f->steps->map(fn (EmailCampaign $s) => [
                'id' => $s->id, 'position' => $s->flow_position, 'delay_hours' => (int) $s->delay_hours, 'subject' => $s->subject,
                'sent_count' => (int) $s->sent_count, 'opened_count' => (int) $s->opened_count, 'clicked_count' => (int) $s->clicked_count,
                ...($detail ? [
                    'preheader' => $s->preheader, 'heading' => $s->heading, 'body' => (string) $s->body,
                    'button_label' => $s->button_label, 'button_url' => $s->button_url, 'discount_code' => $s->discount_code,
                    'product_ids' => $s->product_ids ?? [],
                    'products' => Product::whereIn('id', $s->product_ids ?? [])->get(['id', 'title', 'featured_image']),
                ] : []),
            ])->values(),
        ];
    }
}
