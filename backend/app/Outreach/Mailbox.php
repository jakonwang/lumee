<?php

namespace App\Outreach;

use App\Models\Store;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Webklex\PHPIMAP\ClientManager;

/**
 * The store's own mailbox: sends outreach emails over its SMTP server (so they come from the merchant's
 * address and land in its "Sent" folder through the provider), and reads the inbox over IMAP to see who
 * replied or bounced. Plain-text emails: they read like a person wrote them and are delivered better.
 */
class Mailbox
{
    /**
     * @param  array{in_reply_to?: ?string, references?: string[], unsubscribe?: string}  $thread
     * @return string the Message-ID, for matching replies
     */
    public function send(Store $store, string $to, ?string $toName, string $subject, string $body, array $thread = []): string
    {
        $s = MailboxSettings::raw($store);
        $domain = Str::after($s['from_email'], '@') ?: 'localhost';
        $messageId = Str::uuid()->toString().'@'.$domain;
        $email = (new Email())
            ->from(new Address($s['from_email'], $s['from_name'] ?: $store->name))
            ->to(new Address($to, (string) $toName))
            ->subject($subject)
            ->text($body);
        $h = $email->getHeaders();
        $h->addIdHeader('Message-ID', $messageId);
        if (! empty($thread['in_reply_to'])) {
            $h->addIdHeader('In-Reply-To', $thread['in_reply_to']);
            $h->addIdHeader('References', $thread['references'] ?? [$thread['in_reply_to']]);
        }
        if (! empty($thread['unsubscribe'])) {
            $h->addTextHeader('List-Unsubscribe', '<'.$thread['unsubscribe'].'>');
            $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }
        (new Mailer(Transport::fromDsn($this->dsn($store))))->send($email);

        return $messageId;
    }

    /**
     * Inbox messages since a moment, unread state left as it was.
     *
     * @return array<int, array{from: string, subject: string, in_reply_to: ?string, references: string[], text: string, auto: bool, date: ?string}>
     */
    public function inbox(Store $store, \DateTimeInterface $since): array
    {
        $client = $this->imap($store);
        $client->connect();
        try {
            $messages = $client->getFolder('INBOX')->query()->whereSince($since)->leaveUnread()->setFetchBody(true)->limit(300)->get();
            $out = [];
            foreach ($messages as $m) {
                $header = fn (string $k) => trim((string) ($m->getHeader()->get($k)?->toString() ?? ''));
                $ids = fn (string $v) => preg_match_all('/<([^>]+)>/', $v, $x) ? $x[1] : array_filter([trim($v, '<> ')]);
                $from = $m->getFrom()->first();
                $out[] = [
                    'from' => mb_strtolower((string) ($from?->mail ?? '')),
                    'subject' => (string) $m->getSubject(),
                    'in_reply_to' => $ids($header('in_reply_to'))[0] ?? null,
                    'references' => $ids($header('references')),
                    'text' => Str::limit(trim((string) $m->getTextBody()), 4000, ''),
                    'auto' => $header('auto_submitted') !== '' && $header('auto_submitted') !== 'no' || $header('x_autoreply') !== '' || $header('x_autorespond') !== '',
                    'date' => $m->getDate()?->first()?->toIso8601String(),
                ];
            }

            return $out;
        } finally {
            $client->disconnect();
        }
    }

    /** "Test connection": log in without sending anything. */
    public function test(Store $store, string $service): void
    {
        if ($service === 'smtp') {
            $transport = Transport::fromDsn($this->dsn($store));
            if (method_exists($transport, 'start')) {
                $transport->start();
                $transport->stop();
            }

            return;
        }
        $client = $this->imap($store);
        $client->connect();
        $client->disconnect();
    }

    private function dsn(Store $store): string
    {
        $s = MailboxSettings::raw($store);
        $password = MailboxSettings::smtpPassword($store) ?? throw new RuntimeException('还没有填写发信邮箱的密码');
        $scheme = $s['smtp_encryption'] === 'ssl' ? 'smtps' : 'smtp';   // smtp = STARTTLS when the server offers it
        $user = $s['smtp_username'] !== '' ? $s['smtp_username'] : $s['from_email'];

        return sprintf('%s://%s:%s@%s:%d', $scheme, rawurlencode($user), rawurlencode($password), $s['smtp_host'], (int) $s['smtp_port']);
    }

    private function imap(Store $store)
    {
        $s = MailboxSettings::raw($store);

        return (new ClientManager())->make([
            'host' => $s['imap_host'], 'port' => (int) $s['imap_port'],
            'encryption' => $s['imap_encryption'] === 'none' ? false : $s['imap_encryption'],
            'validate_cert' => true,
            'username' => $s['imap_username'] !== '' ? $s['imap_username'] : $s['from_email'],
            'password' => MailboxSettings::imapPassword($store) ?? throw new RuntimeException('还没有填写收信邮箱的密码'),
            'protocol' => 'imap', 'timeout' => 30,
        ]);
    }
}
