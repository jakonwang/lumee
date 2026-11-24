<?php

namespace App\Outreach;

use App\LeadFinder\LeadAi;
use App\Marketing\Creators;
use App\Models\Creator;
use App\Models\Lead;
use App\Models\OutreachEnrollment;
use App\Models\OutreachMessage;
use App\Models\OutreachSequence;
use App\Models\OutreachSuppression;
use App\Models\Store;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Outreach sequences (开发信序列): enrol leads or creators, then send each step from the store's own
 * mailbox at a human pace — one email at a time, at least `gap_minutes` apart, at most `daily_limit` a
 * day, only during the recipient's working hours (their country's time zone), never on weekends.
 *
 * Step 1 can be written by AI for each recipient in their language (the lead finder / creators outreach);
 * later steps are the sequence's templates, sent as replies in the same thread ("Re: …").
 * Every email ends with the sender's postal address and an unsubscribe link (CAN-SPAM, GDPR).
 * Replies, unsubscribes and bounces stop the sequence (App\Outreach\ReplyChecker).
 */
class Sequencer
{
    public const MAX_FAILURES = 3;

    /** Countries spanning several time zones: the business centre. */
    private const ZONES = [
        'US' => 'America/Chicago', 'CA' => 'America/Toronto', 'MX' => 'America/Mexico_City', 'BR' => 'America/Sao_Paulo', 'AU' => 'Australia/Sydney',
        'RU' => 'Europe/Moscow', 'ID' => 'Asia/Jakarta', 'CL' => 'America/Santiago', 'AR' => 'America/Argentina/Buenos_Aires', 'ES' => 'Europe/Madrid',
        'PT' => 'Europe/Lisbon', 'CN' => 'Asia/Shanghai', 'KZ' => 'Asia/Almaty', 'NZ' => 'Pacific/Auckland', 'EC' => 'America/Guayaquil',
    ];

    public function __construct(private Mailbox $mailbox) {}

    // ---- enrolment ----

    /**
     * @param  Collection<int, Lead|Creator>  $people
     * @return array{enrolled: int, skipped: array<string, int>}
     */
    public function enroll(Store $store, OutreachSequence $sequence, Collection $people): array
    {
        $skipped = [];
        $enrolled = 0;
        $consent = MailboxSettings::raw($store)['skip_consent_countries'] ? MailboxSettings::CONSENT_COUNTRIES : [];
        foreach ($people as $p) {
            $email = self::emailOf($p);
            if (! $email) {
                $skipped['no_email'] = ($skipped['no_email'] ?? 0) + 1;

                continue;
            }
            $why = match (true) {
                OutreachSuppression::blocks($email) => 'unsubscribed',
                in_array(strtoupper((string) $p->country), $consent, true) => 'needs_consent',
                OutreachEnrollment::where('email', $email)->where('status', 'active')->exists() => 'in_sequence',   // one sequence at a time
                OutreachEnrollment::where('sequence_id', $sequence->id)->where('email', $email)->exists() => 'already',
                default => null,
            };
            if ($why) {
                $skipped[$why] = ($skipped[$why] ?? 0) + 1;

                continue;
            }
            OutreachEnrollment::create([
                'sequence_id' => $sequence->id, 'lead_id' => $p instanceof Lead ? $p->id : null, 'creator_id' => $p instanceof Creator ? $p->id : null,
                'email' => $email, 'name' => Str::limit((string) $p->name, 200, ''), 'country' => $p->country,
                'step' => 0, 'status' => 'active', 'next_at' => now(), 'token' => Str::random(40),
            ]);
            $enrolled++;
        }

        return ['enrolled' => $enrolled, 'skipped' => $skipped];
    }

    /** A real address to write to: published (not our info@ guess), and whose domain receives mail. */
    public static function emailOf(Lead|Creator $p): ?string
    {
        $checks = $p instanceof Lead ? ($p->email_checks ?? []) : [];
        foreach ((array) $p->emails as $e) {
            $c = $checks[$e] ?? null;
            if ($c && (($c['guessed'] ?? false) || ! ($c['mx'] ?? true))) {
                continue;
            }
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                return mb_strtolower($e);
            }
        }

        return null;
    }

    /** New leads / creators matching a sequence's auto-enrol rules. Returns how many were enrolled. */
    public function autoEnroll(Store $store, Collection $people, string $audience): int
    {
        $n = 0;
        foreach (OutreachSequence::where('status', 'active')->where('audience', $audience)->get() as $seq) {
            $rules = (array) ($seq->auto ?? []);
            if (empty($rules['enabled'])) {
                continue;
            }
            $match = $people->filter(fn ($p) => $p->score >= (int) ($rules['min_score'] ?? 60)
                && (empty($rules['segments']) || ! $p instanceof Lead || in_array($p->segment, (array) $rules['segments'], true))
                && (empty($rules['countries']) || in_array($p->country, (array) $rules['countries'], true)));
            $n += $this->enroll($store, $seq, $match)['enrolled'];
        }

        return $n;
    }

    // ---- sending ----

    /** Send at most one due email for the store, if its pace allows. Returns the message, or null. */
    public function runStore(Store $store): ?OutreachMessage
    {
        if (! MailboxSettings::canSend($store)) {
            return null;
        }
        $s = MailboxSettings::raw($store);
        $tz = $store->timezone ?: 'UTC';
        $sentToday = OutreachMessage::where('status', 'sent')->where('sent_at', '>=', CarbonImmutable::now($tz)->startOfDay()->utc())->count();
        if ($sentToday >= (int) $s['daily_limit']) {
            return null;
        }
        $last = OutreachMessage::where('status', 'sent')->max('sent_at');
        if ($last && CarbonImmutable::parse($last)->addMinutes((int) $s['gap_minutes'])->isFuture()) {
            return null;
        }
        $due = OutreachEnrollment::with('sequence')->where('status', 'active')->where('next_at', '<=', now())
            ->whereHas('sequence', fn ($q) => $q->where('status', 'active'))->orderBy('next_at')->limit(100)->get();
        foreach ($due as $e) {
            if ($this->workingHours($e->country, (int) $s['window_start'], (int) $s['window_end'])) {
                return $this->send($store, $e);
            }
        }

        return null;
    }

    /** Is it a weekday between start and end o'clock where the recipient is? */
    public function workingHours(?string $country, int $start, int $end, ?CarbonImmutable $now = null): bool
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone(self::zone($country));

        return ! $local->isWeekend() && $local->hour >= $start && $local->hour < $end;
    }

    public static function zone(?string $country): string
    {
        $country = strtoupper((string) $country);
        if (isset(self::ZONES[$country])) {
            return self::ZONES[$country];
        }

        return rescue(fn () => DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country)[0] ?? 'UTC', 'UTC', false);
    }

    public function send(Store $store, OutreachEnrollment $e): OutreachMessage
    {
        $sequence = $e->sequence;
        $steps = array_values((array) $sequence->steps);
        $step = $e->step;
        if (OutreachSuppression::blocks($e->email)) {
            $e->forceFill(['status' => 'unsubscribed', 'next_at' => null])->save();

            return new OutreachMessage(['status' => 'failed', 'error' => 'suppressed']);
        }
        [$subject, $body] = $this->compose($store, $e, $steps, $step);
        $first = $e->messages()->where('status', 'sent')->orderBy('step')->get(['message_id']);
        $msg = new OutreachMessage(['enrollment_id' => $e->id, 'step' => $step, 'subject' => Str::limit($subject, 300, ''), 'body' => $body]);
        try {
            $id = $this->mailbox->send($store, $e->email, $e->name, $subject, $body, [
                'in_reply_to' => $first->last()?->message_id,
                'references' => $first->pluck('message_id')->filter()->values()->all(),
                'unsubscribe' => self::unsubscribeUrl($store, $e),
            ]);
        } catch (Throwable $ex) {
            $error = Str::limit($ex->getMessage(), 480, '');
            $msg->forceFill(['status' => 'failed', 'error' => $error])->save();
            $failures = $e->failures + 1;
            // 550 / 551 / 553 = the address doesn't exist: never write to it again
            if (preg_match('/\b55[0-3]\b/', $error)) {
                OutreachSuppression::add($e->email, 'bounced');
                $e->forceFill(['status' => 'bounced', 'next_at' => null, 'error' => $error, 'failures' => $failures])->save();
            } else {
                $e->forceFill(['status' => $failures >= self::MAX_FAILURES ? 'failed' : 'active', 'next_at' => now()->addHour(), 'error' => $error, 'failures' => $failures])->save();
            }

            return $msg;
        }
        $msg->forceFill(['status' => 'sent', 'message_id' => $id, 'sent_at' => now()])->save();
        $next = $step + 1;
        $e->forceFill([
            'step' => $next, 'last_sent_at' => now(), 'failures' => 0, 'error' => null,
            'status' => isset($steps[$next]) ? 'active' : 'completed',
            'next_at' => isset($steps[$next]) ? now()->addDays(max(1, (int) ($steps[$next]['delay_days'] ?? 3)))->addMinutes(random_int(0, 90)) : null,
        ])->save();
        $this->record($e, $subject, $step);

        return $msg;
    }

    /**
     * Subject and body of a step for a recipient. Step 1: the AI's personal email (kept, so the preview and
     * the sent email are the same), else the template. Later steps reply in the same thread.
     *
     * @return array{0: string, 1: string}
     */
    public function compose(Store $store, OutreachEnrollment $e, array $steps, int $step): array
    {
        $tpl = $steps[$step] ?? ['subject' => '', 'body' => ''];
        $vars = $this->variables($store, $e);
        $fill = fn (string $text) => strtr($text, $vars);
        if ($step === 0) {
            $first = $e->first ?? ($e->sequence->ai_first ? $this->aiFirst($store, $e) : null);
            [$subject, $body] = $first ? [$first['subject'], $first['body']] : [$fill((string) $tpl['subject']), $fill((string) $tpl['body'])];
        } else {
            $firstSubject = $e->messages()->where('status', 'sent')->orderBy('step')->value('subject')
                ?? ($e->first['subject'] ?? $fill((string) ($steps[0]['subject'] ?? '')));
            $translated = $e->first['followups'][$step - 1] ?? null;   // in the recipient's language, when AI wrote the first email
            $subject = trim((string) ($tpl['subject'] ?? '')) !== '' ? $fill($translated['subject'] ?? $tpl['subject']) : 'Re: '.preg_replace('/^(re:\s*)+/i', '', $firstSubject);
            $body = $fill((string) ($translated['body'] ?? $tpl['body']));
        }

        return [trim($subject), $this->footer($store, $e, trim($body))];
    }

    /** {{name}}, {{first_name}}, {{company}}, {{sender_name}}, {{sender_company}}, {{website}} */
    public function variables(Store $store, OutreachEnrollment $e): array
    {
        $sender = \App\LeadFinder\LeadSettings::sender($store);
        $s = MailboxSettings::raw($store);
        $company = (string) ($e->lead?->name ?? $e->creator?->name ?? $e->name);
        $person = $e->lead?->contacts[0]['name'] ?? null;

        return [
            '{{name}}' => $person ?: $company,
            '{{first_name}}' => $person ? Str::before($person, ' ') : ($e->creator ? Str::before($company, ' ') : $company),   // no contact name: the company, in any language
            '{{company}}' => $company,
            '{{sender_name}}' => $s['from_name'] ?: $sender['name'],
            '{{sender_company}}' => $sender['company'] ?: $store->name,
            '{{website}}' => $sender['website'] ?: $store->storefrontUrl(),
        ];
    }

    private function aiFirst(Store $store, OutreachEnrollment $e): ?array
    {
        try {
            if ($e->lead && LeadAi::ready($store)) {
                $r = app(LeadAi::class)->outreach($store, $e->lead);
                $first = ['subject' => $r['subject'], 'body' => $r['body'], 'language' => $r['language'] ?? null];
            } elseif ($e->creator) {
                $r = app(Creators::class)->outreach($store, $e->creator);   // AI when the store has a key, else the template
                $first = ['subject' => $r['subject'], 'body' => $r['message'], 'language' => null];
            } else {
                return null;
            }
        } catch (Throwable $ex) {
            report($ex);

            return null;   // the sequence's own first step is used instead
        }
        $first['followups'] = $this->translateFollowUps($store, $e, $first['language'] ?? null);
        $e->forceFill(['first' => $first])->save();

        return $first;
    }

    /**
     * Follow-ups in the language the AI wrote the first email in (the templates are usually written once,
     * in English or Chinese). Placeholders like {{first_name}} are kept. Null when not needed or not possible.
     */
    private function translateFollowUps(Store $store, OutreachEnrollment $e, ?string $language): ?array
    {
        $steps = array_slice(array_values((array) $e->sequence->steps), 1);
        if (! $language || ! $steps || ! LeadAi::ready($store)) {
            return null;
        }
        $name = LeadAi::LANG_NAMES[$language] ?? $language;
        $items = json_encode(array_map(fn ($s) => ['subject' => (string) ($s['subject'] ?? ''), 'body' => (string) $s['body']], $steps), JSON_UNESCAPED_UNICODE);
        $prompt = "Translate these follow-up emails into {$name}, natural and short, as a business person would write them. "
            .'Keep every {{placeholder}} exactly as it is, keep line breaks, keep empty subjects empty. If a text is already in '.$name.", return it unchanged.\n\nEmails (JSON): {$items}";
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['emails'], 'properties' => ['emails' => ['type' => 'array', 'items' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['subject', 'body'], 'properties' => ['subject' => ['type' => 'string'], 'body' => ['type' => 'string']],
        ]]]];
        try {
            $r = app(LeadAi::class)->json($store, $prompt, $schema, 'low', 3000);
        } catch (Throwable $ex) {
            report($ex);

            return null;
        }
        $out = array_values((array) ($r['emails'] ?? []));

        return count($out) === count($steps) ? array_map(fn ($x) => ['subject' => (string) $x['subject'], 'body' => (string) $x['body']], $out) : null;
    }

    private function footer(Store $store, OutreachEnrollment $e, string $body): string
    {
        $s = MailboxSettings::raw($store);
        $lang = \App\LeadFinder\LeadProfiles::countries()[$e->country ?? '']['lang'] ?? 'en';
        $unsub = [
            'es' => 'Si prefieres no recibir más correos nuestros:', 'pt' => 'Se preferir não receber mais e-mails nossos:',
            'fr' => 'Si vous préférez ne plus recevoir nos e-mails :', 'de' => 'Wenn Sie keine weiteren E-Mails wünschen:',
            'it' => 'Se preferisci non ricevere altre email:',
        ][$lang] ?? 'If you would rather not hear from us again:';
        $sig = trim((string) $s['signature']);

        return $body.($sig !== '' && ! str_contains($body, $sig) ? "\n\n".$sig : '')
            ."\n\n--\n".trim((string) $s['address'])."\n".$unsub.' '.self::unsubscribeUrl($store, $e);
    }

    public static function unsubscribeUrl(Store $store, OutreachEnrollment $e): string
    {
        return $store->storefrontUrl().'/outreach/unsubscribe/'.$e->token;
    }

    /** The lead / creator shows it was contacted (status, follow-up history). */
    private function record(OutreachEnrollment $e, string $subject, int $step): void
    {
        if ($lead = $e->lead) {
            if (in_array($lead->status, ['new', null], true)) {
                $lead->status = 'contacted';
            }
            $lead->contacted_at ??= now();
            $lead->save();
            $lead->activities()->create(['type' => 'email', 'body' => '开发信序列第 '.($step + 1).' 封：'.$subject]);
        }
        if (($c = $e->creator) && $c->status === 'new') {
            $c->forceFill(['status' => 'contacted', 'contacted_at' => $c->contacted_at ?? now()])->save();
        }
    }
}
