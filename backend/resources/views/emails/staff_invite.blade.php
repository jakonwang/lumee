<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('店铺后台邀请') }}</title></head>
<body style="margin:0;padding:0;background:#F7F9F8;font-family:-apple-system,'Segoe UI',Helvetica,Arial,'PingFang SC','Microsoft YaHei',sans-serif;color:#1C2621;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F9F8;">
    <tr><td align="center" style="padding:32px 12px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border:1px solid #E5EAE7;border-radius:12px;">
        <tr><td style="padding:32px;">
          <div style="font-weight:700;font-size:18px;color:#0E8A5F;margin-bottom:24px;">Lumée</div>
          <h1 style="font-size:22px;margin:0 0 12px;">{{ __('加入「:p0」', ['p0' => $store->name]) }}</h1>
          <p style="font-size:15px;line-height:1.7;margin:0 0 16px;">{{ __(':p0 邀请你成为「:p1」店铺后台的员工。你将可以管理：', ['p0' => $inviterName, 'p1' => $store->name]) }}</p>
          <p style="font-size:14px;line-height:1.8;margin:0 0 24px;color:#3A4A42;background:#F2FAF6;border-radius:8px;padding:12px 16px;">
            {{ implode(__('、'), array_map(fn ($l) => __($l), $labels)) }}
          </p>
          <table role="presentation" cellpadding="0" cellspacing="0"><tr><td bgcolor="#0E8A5F" style="border-radius:8px;">
            <a href="{{ $url }}" style="display:inline-block;padding:12px 28px;color:#FFFFFF;font-weight:600;font-size:15px;text-decoration:none;">{{ __('接受邀请') }}</a>
          </td></tr></table>
          <p style="font-size:12px;line-height:1.7;color:#6B7770;margin:24px 0 0;">
            {{ __('邀请链接 :p0 天内有效，只能使用一次。如果你不认识邀请人，忽略这封邮件即可。', ['p0' => $days]) }}<br>
            {{ __('按钮无法点击时，复制此链接到浏览器打开：') }}<br><span style="word-break:break-all;">{{ $url }}</span>
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
