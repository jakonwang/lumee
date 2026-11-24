<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\LeadFinder\LeadAi;
use App\Models\Creator;
use App\Models\Lead;
use App\Models\LeadSearch;
use App\Models\OutreachEnrollment;
use App\Models\OutreachMessage;
use App\Models\OutreachSequence;
use App\Models\OutreachSuppression;
use App\Outreach\Mailbox;
use App\Outreach\MailboxSettings;
use App\Outreach\Sequencer;
use App\Tenancy\CurrentStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Marketing › Outreach (开发信): the sending mailbox, sequences, who is in them, and what happened. */
class OutreachController extends Controller
{
    public function index(CurrentStore $current): JsonResponse
    {
        $store = $current->getOrFail();
        $stats = OutreachEnrollment::selectRaw('sequence_id, status, COUNT(*) as n')->groupBy('sequence_id', 'status')->get()->groupBy('sequence_id');
        $sent = OutreachMessage::where('outreach_messages.status', 'sent')->join('outreach_enrollments', 'outreach_enrollments.id', '=', 'outreach_messages.enrollment_id')
            ->selectRaw('outreach_enrollments.sequence_id, COUNT(*) as n')->groupBy('outreach_enrollments.sequence_id')->pluck('n', 'sequence_id');
        $tz = $store->timezone ?: 'UTC';

        return response()->json([
            'mailbox' => MailboxSettings::public($store),
            'sent_today' => OutreachMessage::where('status', 'sent')->where('sent_at', '>=', CarbonImmutable::now($tz)->startOfDay()->utc())->count(),
            'ai_ready' => LeadAi::ready($store),
            'sequences' => OutreachSequence::latest('id')->get()->map(function ($s) use ($stats, $sent) {
                $by = ($stats[$s->id] ?? collect())->pluck('n', 'status')->map(fn ($n) => (int) $n);
                $total = $by->sum();
                $contacted = $total - ($by['active'] ?? 0) + OutreachEnrollment::where('sequence_id', $s->id)->where('status', 'active')->where('step', '>', 0)->count();

                return [...$s->only('id', 'name', 'audience', 'steps', 'ai_first', 'auto', 'status'), 'counts' => $by, 'total' => $total,
                    'sent' => (int) ($sent[$s->id] ?? 0), 'reply_rate' => $contacted ? round(($by['replied'] ?? 0) / $contacted * 100, 1) : null];
            }),
            'suppressed' => OutreachSuppression::count(),
        ]);
    }

    public function saveMailbox(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'from_name' => ['nullable', 'string', 'max:100'],
            'from_email' => ['required', 'email', 'max:255'],
            'smtp_host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'smtp_port' => ['required', 'integer', Rule::in([25, 465, 587, 2525])],
            'smtp_encryption' => ['required', Rule::in(['tls', 'ssl'])],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:500'],
            'clear_smtp_password' => ['boolean'],
            'imap_host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]*$/'],
            'imap_port' => ['nullable', 'integer', Rule::in([143, 993])],
            'imap_encryption' => ['nullable', Rule::in(['ssl', 'tls', 'none'])],
            'imap_username' => ['nullable', 'string', 'max:255'],
            'imap_password' => ['nullable', 'string', 'max:500'],
            'clear_imap_password' => ['boolean'],
            'daily_limit' => ['required', 'integer', 'min:1', 'max:300'],
            'window_start' => ['required', 'integer', 'min:0', 'max:23'],
            'window_end' => ['required', 'integer', 'min:1', 'max:24', 'gt:window_start'],
            'gap_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'signature' => ['nullable', 'string', 'max:2000'],
            'address' => ['required', 'string', 'min:10', 'max:300'],
            'skip_consent_countries' => ['boolean'],
        ], ['address.required' => __('请填写公司地址：法律要求营销邮件写明发件人的实际地址'), 'smtp_host.regex' => __('服务器地址格式不对'), 'imap_host.regex' => __('服务器地址格式不对')]);
        foreach (['from_name', 'smtp_username', 'imap_host', 'imap_username', 'signature'] as $k) {
            $data[$k] = trim((string) ($data[$k] ?? ''));
        }
        MailboxSettings::save($current->getOrFail(), $data);

        return response()->json(['mailbox' => MailboxSettings::public($current->getOrFail()->fresh())]);
    }

    public function testMailbox(Request $request, CurrentStore $current, Mailbox $mailbox): JsonResponse
    {
        $service = $request->validate(['service' => ['required', Rule::in(['smtp', 'imap'])]])['service'];
        try {
            $mailbox->test($current->getOrFail(), $service);

            return response()->json(['ok' => true, 'message' => $service === 'smtp' ? __('发信服务器连接成功') : __('收信服务器连接成功，可以自动识别回复')]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => Str::limit($e->getMessage(), 300)]);
        }
    }

    // ---- sequences ----

    public function store(Request $request): JsonResponse
    {
        $s = OutreachSequence::create($this->validated($request));

        return response()->json(['data' => $s], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $s = OutreachSequence::findOrFail($id);
        $s->fill($this->validated($request, $s))->save();

        return response()->json(['data' => $s]);
    }

    public function destroy(int $id): JsonResponse
    {
        OutreachSequence::findOrFail($id)->delete();   // enrolments and history go with it

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, ?OutreachSequence $existing = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'audience' => [$existing ? 'sometimes' : 'required', Rule::in(['leads', 'creators'])],
            'steps' => ['required', 'array', 'min:1', 'max:5'],
            'steps.*.delay_days' => ['required', 'integer', 'min:0', 'max:60'],
            'steps.*.subject' => ['nullable', 'string', 'max:200'],
            'steps.*.body' => ['required', 'string', 'max:5000'],
            'ai_first' => ['boolean'],
            'auto' => ['nullable', 'array'],
            'auto.enabled' => ['boolean'],
            'auto.min_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'auto.segments' => ['nullable', 'array'],
            'auto.segments.*' => ['string', 'max:20'],
            'auto.countries' => ['nullable', 'array'],
            'auto.countries.*' => ['string', 'size:2'],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
        ], ['steps.*.body.required' => __('每一封邮件都要填写正文')]);
        if (trim((string) ($data['steps'][0]['subject'] ?? '')) === '' && ! ($data['ai_first'] ?? $existing?->ai_first ?? true)) {
            throw ValidationException::withMessages(['steps.0.subject' => __('第一封邮件需要标题（或者让 AI 写第一封）')]);
        }
        $data['steps'] = array_map(fn ($st) => ['delay_days' => (int) $st['delay_days'], 'subject' => trim((string) ($st['subject'] ?? '')), 'body' => trim($st['body'])], $data['steps']);

        return $data;
    }

    // ---- recipients ----

    public function enrollments(Request $request, int $id): JsonResponse
    {
        $seq = OutreachSequence::findOrFail($id);
        $list = OutreachEnrollment::with(['lead:id,name,score', 'creator:id,name,score,platform'])->where('sequence_id', $seq->id)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("CASE status WHEN 'replied' THEN 0 WHEN 'active' THEN 1 ELSE 2 END")->latest('id')->paginate(50);

        return response()->json([
            'data' => collect($list->items())->map(fn (OutreachEnrollment $e) => [...$e->only('id', 'email', 'name', 'country', 'step', 'status', 'next_at', 'last_sent_at', 'error', 'lead_id', 'creator_id'),
                'score' => $e->lead?->score ?? $e->creator?->score]),
            'meta' => ['total' => $list->total(), 'page' => $list->currentPage(), 'last_page' => $list->lastPage()],
        ]);
    }

    /** Add leads or creators (by id) to a sequence. */
    public function enroll(Request $request, int $id, CurrentStore $current, Sequencer $sequencer): JsonResponse
    {
        $seq = OutreachSequence::findOrFail($id);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer']]);
        $people = $seq->audience === 'creators' ? Creator::whereIn('id', $data['ids'])->get() : Lead::whereIn('id', $data['ids'])->get();   // this store's only

        return response()->json($sequencer->enroll($current->getOrFail(), $seq, $people));
    }

    public function stop(int $id): JsonResponse
    {
        $e = OutreachEnrollment::findOrFail($id);
        if ($e->status === 'active') {
            $e->forceFill(['status' => 'stopped', 'next_at' => null])->save();
        }

        return response()->json(['ok' => true]);
    }

    /** The emails a recipient will get (AI first email included, then kept so the sent one is the same). */
    public function preview(int $id, CurrentStore $current, Sequencer $sequencer): JsonResponse
    {
        $e = OutreachEnrollment::with('sequence')->findOrFail($id);
        $steps = array_values((array) $e->sequence->steps);
        $out = [];
        foreach ($steps as $i => $st) {
            [$subject, $body] = $sequencer->compose($current->getOrFail(), $e, $steps, $i);
            $out[] = ['step' => $i, 'delay_days' => $st['delay_days'], 'subject' => $subject, 'body' => $body,
                'sent' => $e->messages()->where('step', $i)->where('status', 'sent')->value('sent_at')];
        }

        return response()->json(['data' => $out]);
    }

    /** Lead finder: repeat a search every day. */
    public function repeatSearch(Request $request, int $id): JsonResponse
    {
        $s = LeadSearch::findOrFail($id);
        $s->forceFill(['repeat_daily' => $request->validate(['repeat_daily' => ['required', 'boolean']])['repeat_daily']])->save();

        return response()->json(['data' => $s]);
    }
}
