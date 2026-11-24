<?php

namespace App\Outreach;

use App\Models\OutreachEnrollment;
use App\Models\OutreachMessage;
use App\Models\OutreachSuppression;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Reads the store's inbox (IMAP) and stops sequences:
 *  - a reply (In-Reply-To / References point at one of our emails, or it comes from an enrolled address)
 *    → "replied": no more follow-ups; the lead / creator shows the reply
 *  - a bounce (mailer-daemon, "Undeliverable"…) naming an enrolled address → "bounced", never emailed again
 *  - out-of-office auto-replies are ignored (the sequence goes on)
 */
class ReplyChecker
{
    private const BOUNCE_FROM = '/^(mailer-daemon|postmaster|mail-daemon|mailerdaemon)@/i';

    private const BOUNCE_SUBJECT = '/(undeliver|delivery status notification|mail delivery (failed|failure|subsystem)|returned mail|failure notice|delivery has failed|无法投递|退信|退回)/iu';

    private const OUT_OF_OFFICE = '/(out of (the )?office|automatic reply|auto(matic)?[- ]?reply|respuesta autom[aá]tica|resposta autom[aá]tica|r[ée]ponse automatique|abwesenheit|自动回复|休假)/iu';

    public function __construct(private Mailbox $mailbox) {}

    /** @return array{replied: int, bounced: int} */
    public function check(Store $store): array
    {
        $out = ['replied' => 0, 'bounced' => 0];
        if (! MailboxSettings::canReadReplies($store)) {
            return $out;
        }
        $s = MailboxSettings::raw($store);
        $since = $s['last_reply_check'] ? CarbonImmutable::parse($s['last_reply_check'])->subDay() : CarbonImmutable::now()->subDays(14);
        $started = now();

        $mine = OutreachMessage::where('status', 'sent')->where('sent_at', '>=', now()->subDays(90))->whereNotNull('message_id')
            ->pluck('enrollment_id', 'message_id');
        $byEmail = OutreachEnrollment::whereIn('status', ['active', 'completed'])->get()->groupBy('email');

        foreach ($this->mailbox->inbox($store, $since) as $m) {
            if (preg_match(self::BOUNCE_FROM, $m['from']) || preg_match(self::BOUNCE_SUBJECT, $m['subject'])) {
                $out['bounced'] += $this->bounce($m, $mine, $byEmail);

                continue;
            }
            if ($m['auto'] || preg_match(self::OUT_OF_OFFICE, $m['subject'])) {
                continue;
            }
            $ids = array_filter([$m['in_reply_to'], ...$m['references']]);
            $enrollmentId = collect($ids)->map(fn ($id) => $mine[$id] ?? null)->filter()->first();
            $enrollments = $enrollmentId ? OutreachEnrollment::whereKey($enrollmentId)->get() : ($byEmail[$m['from']] ?? collect());
            foreach ($enrollments as $e) {
                if (in_array($e->status, ['active', 'completed'], true)) {
                    $this->replied($e, $m);
                    $out['replied']++;
                }
            }
        }
        MailboxSettings::touch($store, 'last_reply_check', $started->toIso8601String());

        return $out;
    }

    private function replied(OutreachEnrollment $e, array $m): void
    {
        $e->forceFill(['status' => 'replied', 'next_at' => null])->save();
        $snippet = Str::limit(trim(preg_replace('/\s+/u', ' ', preg_split('/\n(>|On .+ wrote:|El .+ escribió:|Em .+ escreveu:)/u', $m['text'])[0] ?? '')), 300);
        if ($lead = $e->lead) {
            if (in_array($lead->status, ['new', 'contacted'], true)) {
                $lead->forceFill(['status' => 'replied'])->save();
            }
            $lead->activities()->create(['type' => 'email', 'body' => '收到回复：'.$m['subject'].($snippet !== '' ? "\n".$snippet : '')]);
        }
        if (($c = $e->creator) && in_array($c->status, ['new', 'contacted'], true)) {
            $c->forceFill(['status' => 'replied'])->save();
        }
    }

    private function bounce(array $m, $mine, $byEmail): int
    {
        $ids = array_filter([$m['in_reply_to'], ...$m['references']]);
        $enrollmentId = collect($ids)->map(fn ($id) => $mine[$id] ?? null)->filter()->first();
        $candidates = $enrollmentId ? OutreachEnrollment::whereKey($enrollmentId)->get()
            : $byEmail->keys()->filter(fn ($email) => str_contains(mb_strtolower($m['text']), $email))->flatMap(fn ($email) => $byEmail[$email]);
        foreach ($candidates as $e) {
            OutreachSuppression::add($e->email, 'bounced');
            $e->forceFill(['status' => 'bounced', 'next_at' => null, 'error' => Str::limit($m['subject'], 200, '')])->save();
        }

        return count($candidates);
    }
}
