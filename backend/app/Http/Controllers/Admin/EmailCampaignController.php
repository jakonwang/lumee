<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Marketing\CampaignSender;
use App\Marketing\Newsletter;
use App\Models\Discount;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\Product;
use App\Notifications\CampaignMail;
use App\Support\Tr;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Email marketing: campaigns (draft → test → send now / schedule) and the signup popup. */
class EmailCampaignController extends Controller
{
    public function index(CurrentStore $current, CampaignSender $sender): JsonResponse
    {
        $store = $current->getOrFail();

        return response()->json([
            'data' => EmailCampaign::whereNull('email_flow_id')->latest('id')->get()->map(fn (EmailCampaign $c) => $this->present($c)),
            'audiences' => collect(EmailCampaign::AUDIENCES)->map(fn ($label, $key) => [
                'key' => $key, 'label' => __($label), 'count' => $sender->audience($store, $key)->count(),
            ])->values(),
            'popup' => Newsletter::popup($store),
            'quota' => app(\App\Marketing\SendQuota::class)->summary($store),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->present(EmailCampaign::findOrFail($id), true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $campaign = new EmailCampaign(['status' => 'draft']);
        $campaign->fill($this->validated($request))->save();

        return response()->json(['data' => $this->present($campaign, true)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $campaign = EmailCampaign::findOrFail($id);
        $this->assertEditable($campaign);
        $campaign->fill($this->validated($request))->save();

        return response()->json(['data' => $this->present($campaign, true)]);
    }

    public function destroy(int $id): Response
    {
        $campaign = EmailCampaign::findOrFail($id);
        if ($campaign->status === 'sending') {
            throw ValidationException::withMessages(['campaign' => __('正在发送的活动不能删除')]);
        }
        $campaign->delete();

        return response()->noContent();
    }

    /** Rendered email, exactly as recipients will see it (links not tracked). */
    public function preview(int $id, CurrentStore $current): Response
    {
        $campaign = EmailCampaign::findOrFail($id);
        $store = $current->getOrFail();
        app(\App\Promotions\Promotions::class)->enableFor($store);
        $html = app(\App\I18n\Localization::class)->within($store, \App\I18n\Localization::storeDefault($store),
            fn () => (new CampaignMail($campaign, $store, new EmailCampaignRecipient(['email' => 'preview@example.com', 'token' => 'test']), test: true))->render());

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function test(Request $request, int $id, CurrentStore $current, CampaignSender $sender): JsonResponse
    {
        $campaign = EmailCampaign::findOrFail($id);
        $to = $request->validate(['email' => ['nullable', 'email', 'max:255']])['email'] ?? $request->user()->email;
        $store = $current->getOrFail();
        app(\App\I18n\Localization::class)->within($store, \App\I18n\Localization::storeDefault($store), fn () => $sender->sendTest($campaign, $store, $to));

        return response()->json(['message' => __('测试邮件已发送到 :p0', ['p0' => $to])]);
    }

    /** Send now, or schedule (scheduled_at in the future). */
    public function send(Request $request, int $id, CampaignSender $sender): JsonResponse
    {
        $campaign = EmailCampaign::whereNull('email_flow_id')->findOrFail($id);
        $this->assertEditable($campaign);
        $data = $request->validate(['scheduled_at' => ['nullable', 'date']]);
        if (trim((string) $campaign->subject) === '' || (trim((string) $campaign->body) === '' && empty($campaign->product_ids))) {
            throw ValidationException::withMessages(['campaign' => __('请先填写邮件主题和内容')]);
        }

        $at = filled($data['scheduled_at'] ?? null) ? Carbon::parse($data['scheduled_at']) : null;
        if (! $at || ! $at->isFuture()) {
            $store = \App\Models\Store::findOrFail($campaign->store_id);
            $need = $sender->audience($store, $campaign->audience)->count();
            $left = app(\App\Marketing\SendQuota::class)->remaining($store);
            if ($need > $left) {
                throw ValidationException::withMessages(['campaign' => __('本月还能发送 :p0 封营销邮件，这次需要 :p1 封。请升级套餐，或者缩小收件人范围。', ['p0' => $left, 'p1' => $need])]);
            }
        }
        if ($at && $at->isFuture()) {
            $campaign->forceFill(['status' => 'scheduled', 'scheduled_at' => $at])->save();
        } else {
            $campaign->forceFill(['status' => 'draft', 'scheduled_at' => null])->save();
            $sender->start($campaign);
        }

        return response()->json(['data' => $this->present($campaign->fresh(), true)]);
    }

    public function unschedule(int $id): JsonResponse
    {
        $campaign = EmailCampaign::findOrFail($id);
        if ($campaign->status === 'scheduled') {
            $campaign->forceFill(['status' => 'draft', 'scheduled_at' => null])->save();
        }

        return response()->json(['data' => $this->present($campaign, true)]);
    }

    public function popup(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'heading' => ['required', 'string', 'max:80'],
            'text' => ['nullable', 'string', 'max:300'],
            'button' => ['required', 'string', 'max:30'],
            'discount_code' => ['nullable', 'string', 'max:40'],
            'delay' => ['required', 'integer', 'between:0,60'],
        ], ['heading.required' => __('请填写弹窗标题')]);
        if (filled($data['discount_code'] ?? null) && ! Discount::where('code', Discount::normalizeCode($data['discount_code']))->exists()) {
            throw ValidationException::withMessages(['discount_code' => __('找不到这个折扣码，请先在「折扣」里创建')]);
        }
        Newsletter::savePopup($current->getOrFail(), $data);

        return response()->json(['popup' => Newsletter::popup($current->getOrFail()->fresh())]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'preheader' => ['nullable', 'string', 'max:150'],
            'heading' => ['nullable', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:50000'],
            'button_label' => ['nullable', 'string', 'max:60'],
            'button_url' => ['nullable', 'string', 'max:500', 'regex:#^(https?://|/)#i'],
            'product_ids' => ['nullable', 'array', 'max:4'],
            'product_ids.*' => ['integer'],
            'audience' => ['required', Rule::in(array_keys(EmailCampaign::AUDIENCES))],
        ], ['subject.required' => __('请填写邮件主题'), 'button_url.regex' => __('按钮链接要以 http(s):// 或 / 开头')]);

        $ids = array_values(array_unique(array_map('intval', $data['product_ids'] ?? [])));
        $found = Product::whereIn('id', $ids)->pluck('id')->all();   // store-scoped
        $data['product_ids'] = array_values(array_filter($ids, fn ($i) => in_array($i, $found, true))) ?: null;

        return $data;
    }

    private function assertEditable(EmailCampaign $c): void
    {
        if (! $c->editable()) {
            throw ValidationException::withMessages(['campaign' => __('已经发送的活动不能再修改')]);
        }
    }

    private function present(EmailCampaign $c, bool $detail = false): array
    {
        $rate = fn ($n) => $c->sent_count ? round((int) $n / $c->sent_count * 100, 1) : 0;
        $out = [
            'id' => $c->id,
            'subject' => $c->subject,
            'audience' => $c->audience,
            'audience_label' => __(EmailCampaign::AUDIENCES[$c->audience] ?? $c->audience),
            'status' => $c->status,
            'scheduled_at' => $c->scheduled_at,
            'sent_at' => $c->sent_at,
            'recipients_count' => (int) $c->recipients_count,
            'sent_count' => (int) $c->sent_count,
            'failed_count' => (int) $c->failed_count,
            'opened_count' => (int) $c->opened_count,
            'clicked_count' => (int) $c->clicked_count,
            'unsubscribed_count' => (int) $c->unsubscribed_count,
            'open_rate' => $rate($c->opened_count),
            'click_rate' => $rate($c->clicked_count),
            'updated_at' => $c->updated_at,
        ];
        if ($detail) {
            $out += [
                'preheader' => $c->preheader, 'heading' => $c->heading, 'body' => (string) $c->body,
                'button_label' => $c->button_label, 'button_url' => $c->button_url, 'product_ids' => $c->product_ids ?? [],
                'products' => Product::whereIn('id', $c->product_ids ?? [])->get(['id', 'title', 'featured_image', 'status'])
                    ->sortBy(fn ($p) => array_search($p->id, $c->product_ids ?? [], true))->values(),
            ];
        }

        return $out;
    }
}
