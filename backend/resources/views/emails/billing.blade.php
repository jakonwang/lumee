<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __(\App\Billing\BillingMailer::SUBJECTS[$type], ['p0' => $order?->name()]) }}</title></head>
<body style="margin:0;padding:0;background:#F7F9F8;font-family:-apple-system,'Segoe UI',Helvetica,Arial,'PingFang SC','Microsoft YaHei',sans-serif;color:#1C2621;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F9F8;">
    <tr><td align="center" style="padding:32px 12px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border:1px solid #E5EAE7;border-radius:12px;">
        <tr><td style="padding:32px;">
          <div style="font-weight:700;font-size:18px;color:#0E8A5F;margin-bottom:24px;">Lumée</div>
          @if ($type === 'trial_ending')
            <h1 style="font-size:22px;margin:0 0 12px;">{{ __('「:p0」的免费试用即将结束', ['p0' => $store->name]) }}</h1>
            <p style="font-size:15px;line-height:1.7;margin:0 0 16px;">
              {{ __('试用将于 :p0 结束。试用结束后如果还没有选择套餐，店铺前台会暂停营业，后台只能进入「套餐与账单」页面。商品、订单等数据都会保留。', [
                  'p0' => $store->trial_ends_at?->timezone($store->timezone ?: 'UTC')->format('Y-m-d H:i'),
              ]) }}
            </p>
            <p style="font-size:15px;line-height:1.7;margin:0 0 24px;">{{ __('现在选择套餐，剩余的试用天数不会浪费——试用结束后才开始扣费。') }}</p>
          @elseif ($type === 'dispute_opened')
            @php($d = $order->dispute ?? [])
            <h1 style="font-size:22px;margin:0 0 12px;">{{ __('订单 :p0 收到了拒付（争议）', ['p0' => $order->name()]) }}</h1>
            <p style="font-size:15px;line-height:1.7;margin:0 0 12px;">
              {{ __('买家向发卡银行对这笔付款提出了争议，争议金额 :p0 已被银行暂时扣回。', ['p0' => \App\Support\Money::format($d['amount'] ?? 0).' '.($d['currency'] ?? $order->currency)]) }}
              @if (! empty($d['reason'])) {{ __('原因：:p0。', ['p0' => __(\App\Payments\StripeDisputes::REASONS[$d['reason']] ?? $d['reason'])]) }} @endif
            </p>
            <p style="font-size:15px;line-height:1.7;margin:0 0 24px;">
              {{ __('请尽快整理发货凭证、物流单号和与买家的沟通记录，直接回复这封邮件发给我们，平台会代你向银行提交证据。') }}
              @if (! empty($d['due_by'])) {{ __('提交截止时间：:p0。', ['p0' => \Illuminate\Support\Carbon::parse($d['due_by'])->timezone($store->timezone ?: 'UTC')->format('Y-m-d H:i')]) }} @endif
            </p>
          @elseif ($type === 'dispute_closed')
            @php($d = $order->dispute ?? [])
            <h1 style="font-size:22px;margin:0 0 12px;">{{ __('订单 :p0 的争议已结束', ['p0' => $order->name()]) }}</h1>
            <p style="font-size:15px;line-height:1.7;margin:0 0 24px;">
              {{ ($d['status'] ?? null) === 'won' ? __('银行判定你胜诉，争议金额已退回你的账户。') : __('银行判定买家胜诉，争议金额已退给买家。') }}
            </p>
          @else
            <h1 style="font-size:22px;margin:0 0 12px;">{{ __('「:p0」续费扣款失败', ['p0' => $store->name]) }}</h1>
            <p style="font-size:15px;line-height:1.7;margin:0 0 24px;">
              {{ __('我们没能从你绑定的银行卡扣取套餐费用。店铺会继续正常营业 :p0 天，期间系统会自动重试。请尽快检查银行卡余额或更换付款方式，否则店铺将暂停营业。', ['p0' => $graceDays]) }}
            </p>
          @endif
          <table role="presentation" cellpadding="0" cellspacing="0"><tr><td bgcolor="#0E8A5F" style="border-radius:8px;">
            <a href="{{ $url }}" style="display:inline-block;padding:12px 28px;color:#FFFFFF;font-weight:600;font-size:15px;text-decoration:none;">{{ $order ? __('查看订单') : ($type === 'trial_ending' ? __('选择套餐') : __('查看账单')) }}</a>
          </td></tr></table>
          <p style="font-size:12px;line-height:1.7;color:#6B7770;margin:24px 0 0;">{{ __('这封邮件发给店铺所有者。如有疑问，直接回复即可。') }}</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
