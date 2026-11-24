<?php

namespace App\Outreach;

use App\Models\Store;
use Illuminate\Support\Facades\Crypt;

/**
 * The store's own mailbox for outreach (stores.settings.outreach): SMTP to send, IMAP to see replies,
 * and the sending rules. Passwords are encrypted and never returned to the browser.
 */
final class MailboxSettings
{
    public const DEFAULTS = [
        'from_name' => '', 'from_email' => '',
        'smtp_host' => '', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_username' => '', 'smtp_password' => null,
        'imap_host' => '', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_username' => '', 'imap_password' => null,
        'daily_limit' => 40, 'window_start' => 9, 'window_end' => 17, 'gap_minutes' => 5,
        'signature' => '', 'address' => '', 'skip_consent_countries' => true,
        'last_reply_check' => null,
    ];

    /** Cold email to businesses needs prior consent there (Germany: UWG §7; Austria: TKG §174). */
    public const CONSENT_COUNTRIES = ['DE', 'AT'];

    public static function raw(Store $store): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key($store->settings['outreach'] ?? [], self::DEFAULTS));
    }

    public static function smtpPassword(Store $store): ?string
    {
        return self::decrypt(self::raw($store)['smtp_password']);
    }

    public static function imapPassword(Store $store): ?string
    {
        return self::decrypt(self::raw($store)['imap_password']) ?? self::smtpPassword($store);   // usually the same mailbox password
    }

    public static function canSend(Store $store): bool
    {
        $r = self::raw($store);

        return $r['smtp_host'] !== '' && filter_var($r['from_email'], FILTER_VALIDATE_EMAIL) && self::smtpPassword($store) !== null && trim($r['address']) !== '';
    }

    public static function canReadReplies(Store $store): bool
    {
        $r = self::raw($store);

        return $r['imap_host'] !== '' && self::imapPassword($store) !== null;
    }

    /** For the admin: never the passwords. */
    public static function public(Store $store): array
    {
        $r = self::raw($store);
        unset($r['smtp_password'], $r['imap_password']);

        return [...$r, 'smtp_password_set' => self::smtpPassword($store) !== null, 'imap_password_set' => self::decrypt(self::raw($store)['imap_password']) !== null,
            'can_send' => self::canSend($store), 'can_read_replies' => self::canReadReplies($store)];
    }

    public static function save(Store $store, array $data): void
    {
        $old = self::raw($store);
        $new = array_merge($old, array_intersect_key($data, self::DEFAULTS));
        foreach (['smtp_password', 'imap_password'] as $k) {
            $new[$k] = match (true) {
                ! empty($data['clear_'.$k]) => null,
                filled($data[$k] ?? null) => Crypt::encryptString((string) $data[$k]),
                default => $old[$k],
            };
        }
        $store->forceFill(['settings' => [...($store->settings ?? []), 'outreach' => $new]])->save();
    }

    public static function touch(Store $store, string $key, mixed $value): void
    {
        $s = $store->settings ?? [];
        $s['outreach'][$key] = $value;
        $store->forceFill(['settings' => $s])->save();
    }

    private static function decrypt(?string $v): ?string
    {
        return $v ? rescue(fn () => Crypt::decryptString($v), null, false) : null;
    }
}
