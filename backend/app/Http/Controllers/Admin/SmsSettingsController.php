<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsMessage;
use App\Notifications\Sms;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/** Settings › Notifications › SMS: Twilio credentials, which events text the buyer, recent messages. */
class SmsSettingsController extends Controller
{
    public function show(CurrentStore $current): JsonResponse
    {
        return response()->json($this->payload($current));
    }

    public function update(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'account_sid' => ['nullable', 'string', 'regex:/^AC[a-zA-Z0-9]{32}$/'],
            'auth_token' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'string', 'max:30', 'regex:/^(\+[1-9]\d{6,14}|MG[a-zA-Z0-9]{32}|[A-Za-z0-9 ]{1,11})$/'],
            'events' => ['array'],
            'events.*' => ['boolean'],
        ], [
            'account_sid.regex' => __('Account SID 以 AC 开头，共 34 位'),
            'from.regex' => __('发送号码需要带国家码，例如 +15551234567'),
        ]);
        $store = $current->getOrFail();
        $old = $store->settings['sms'] ?? [];
        $store->settings = [...($store->settings ?? []), 'sms' => [
            'enabled' => $data['enabled'],
            'account_sid' => $data['account_sid'] ?? null,
            // empty = keep the saved token
            'auth_token' => ! empty($data['auth_token']) ? Crypt::encryptString(trim($data['auth_token'])) : ($old['auth_token'] ?? null),
            'from' => $data['from'] ?? null,
            'events' => array_intersect_key(array_map('boolval', $data['events'] ?? []), array_flip(Sms::TYPES)),
        ]];
        if ($data['enabled'] && ! Sms::ready($store)) {
            return response()->json(['message' => __('开启短信前，请填写 Account SID、Auth Token 和发送号码')], 422);
        }
        $store->save();

        return response()->json($this->payload($current));
    }

    /** Send a test text to the merchant's own phone. */
    public function test(Request $request, CurrentStore $current, Sms $sms): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'string', 'max:30']]);
        $store = $current->getOrFail();
        abort_unless(Sms::ready($store), 422, __('请先填写并保存短信设置'));
        $to = Sms::e164($data['to'], null);
        abort_unless($to, 422, __('手机号需要带国家码，例如 +8613800000000'));
        $msg = SmsMessage::create(['type' => 'test', 'to' => $to, 'body' => __(':store：这是一条测试短信，短信通知已经可以使用了。', ['p0' => $store->name, 'store' => $store->name]), 'status' => 'queued']);
        $sms->deliver($msg);

        return response()->json(['message' => $msg->fresh()]);
    }

    private function payload(CurrentStore $current): array
    {
        $s = Sms::settings($current->getOrFail()->fresh());

        return [
            'settings' => [...collect($s)->except('auth_token')->all(), 'auth_token_set' => (bool) $s['auth_token']],
            'recent' => SmsMessage::latest('id')->limit(20)->get(['id', 'order_id', 'type', 'to', 'status', 'error', 'created_at']),
        ];
    }
}
