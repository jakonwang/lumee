<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadSearch;
use App\Models\OutreachEnrollment;
use App\Models\OutreachMessage;
use App\Models\OutreachSuppression;
use App\Models\Store;
use App\Outreach\Mailbox;
use App\Outreach\ReplyChecker;
use App\Outreach\Sequencer;
use App\Tenancy\CurrentStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Outreach sequences: own mailbox, pacing, follow-ups in thread, replies / bounces / unsubscribes stop them. */
class OutreachTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    private FakeMailbox $mail;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lumee.storefront_scheme' => 'http', 'lumee.storefront_port' => null]);
        CarbonImmutable::setTestNow('2026-11-24 16:00:00');   // Tuesday 10:00 in Chicago
        \Illuminate\Support\Carbon::setTestNow('2026-11-24 16:00:00');
        $res = $this->postJson('/api/admin/auth/register', ['name' => 'M', 'email' => 'a@example.com', 'password' => 'secret-pass', 'store_name' => 'Shop A'])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
        $this->mail = new FakeMailbox();
        $this->app->instance(Mailbox::class, $this->mail);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->a['token'], 'X-Store-Id' => (string) $this->a['store_id'], 'Accept' => 'application/json']);
    }

    private function store(): Store
    {
        $s = Store::find($this->a['store_id']);
        app(CurrentStore::class)->set($s);

        return $s;
    }

    private function lead(string $name, string $country, array $emails, array $checks = [], int $score = 70): Lead
    {
        $this->store();

        return Lead::create(['source' => 'manual', 'source_id' => strtolower($name), 'name' => $name, 'country' => $country, 'emails' => $emails,
            'email_checks' => $checks ?: null, 'score' => $score, 'segment' => 'wholesale', 'status' => 'new', 'enrich_status' => 'skipped']);
    }

    private function mailbox(array $extra = []): void
    {
        $this->as()->putJson('/api/admin/outreach/mailbox', [
            'from_name' => 'Fiona', 'from_email' => 'fiona@factory.example', 'smtp_host' => 'smtp.factory.example', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
            'smtp_password' => 'secret', 'imap_host' => 'imap.factory.example', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'daily_limit' => 10, 'window_start' => 9, 'window_end' => 17, 'gap_minutes' => 5, 'signature' => "Fiona Wang\nSales", 'address' => 'No. 1 Industrial Rd, Shenzhen, China',
            'skip_consent_countries' => true, ...$extra,
        ])->assertOk();
    }

    private function sequence(array $extra = []): array
    {
        return $this->as()->postJson('/api/admin/outreach/sequences', [
            'name' => 'Phone accessories buyers', 'audience' => 'leads', 'ai_first' => false,
            'steps' => [
                ['delay_days' => 0, 'subject' => 'Screen protectors for {{company}}', 'body' => "Hi {{first_name}},\n\nWe make screen protectors for shops like {{company}}."],
                ['delay_days' => 3, 'subject' => '', 'body' => 'Just following up on my last email, {{first_name}}.'],
            ],
            ...$extra,
        ])->assertCreated()->json('data');
    }

    public function test_mailbox_settings_need_an_address_and_hide_passwords(): void
    {
        $this->as()->putJson('/api/admin/outreach/mailbox', ['from_email' => 'x@y.example', 'smtp_host' => 'smtp.y.example', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
            'daily_limit' => 10, 'window_start' => 9, 'window_end' => 17, 'gap_minutes' => 5])->assertStatus(422)->assertJsonValidationErrors('address');
        $this->mailbox();
        $r = $this->as()->getJson('/api/admin/outreach')->assertOk();
        $r->assertJsonPath('mailbox.can_send', true)->assertJsonPath('mailbox.can_read_replies', true)->assertJsonPath('mailbox.smtp_password_set', true);
        $this->assertStringNotContainsString('secret', $r->getContent());
        // Leaving the password blank keeps it
        $this->mailbox(['smtp_password' => '', 'daily_limit' => 20]);
        $this->as()->getJson('/api/admin/outreach')->assertJsonPath('mailbox.smtp_password_set', true)->assertJsonPath('mailbox.daily_limit', 20);
    }

    public function test_enrol_send_follow_up_and_stop_on_reply(): void
    {
        $this->mailbox(['daily_limit' => 2]);
        $seq = $this->sequence();
        $acme = $this->lead('Acme Cell', 'US', ['buy@acme.example']);
        $beta = $this->lead('Beta Phones', 'US', ['sales@beta.example']);
        $guess = $this->lead('Guessed Co', 'US', ['info@guessed.example'], ['info@guessed.example' => ['mx' => true, 'guessed' => true, 'kind' => 'role']]);
        $german = $this->lead('Handy GmbH', 'DE', ['info@handy.example']);
        $this->store();
        OutreachSuppression::add('no@gone.example', 'unsubscribed');
        $gone = $this->lead('Gone Ltd', 'US', ['no@gone.example']);

        $this->as()->postJson("/api/admin/outreach/sequences/{$seq['id']}/enroll", ['ids' => [$acme->id, $beta->id, $guess->id, $german->id, $gone->id]])->assertOk()
            ->assertJsonPath('enrolled', 2)->assertJsonPath('skipped.no_email', 1)->assertJsonPath('skipped.needs_consent', 1)->assertJsonPath('skipped.unsubscribed', 1);

        // One email per run, then the gap holds the next one back
        $store = $this->store();
        $seqr = app(Sequencer::class);
        $this->assertSame('sent', $seqr->runStore($store)?->status);
        $this->assertNull($seqr->runStore($store));
        $first = $this->mail->sent[0];
        $this->assertSame('buy@acme.example', $first['to']);
        $this->assertSame('Screen protectors for Acme Cell', $first['subject']);
        $this->assertStringContainsString("Hi Acme Cell,\n\nWe make screen protectors for shops like Acme Cell.", $first['body']);
        $this->assertStringContainsString('No. 1 Industrial Rd, Shenzhen, China', $first['body']);
        $this->assertStringContainsString("Fiona Wang\nSales", $first['body']);
        $token = OutreachEnrollment::where('email', 'buy@acme.example')->value('token');
        $this->assertStringContainsString(self::HOST.'/outreach/unsubscribe/'.$token, $first['body']);
        $this->assertSame(self::HOST.'/outreach/unsubscribe/'.$token, $first['thread']['unsubscribe']);
        $this->assertSame('contacted', $acme->fresh()->status);
        $this->assertTrue($acme->activities()->where('type', 'email')->exists());

        // 6 minutes later: the second recipient; then the daily limit (2) is reached
        $this->travel(6)->minutes();
        CarbonImmutable::setTestNow(now());
        $this->assertSame('sent', $seqr->runStore($store)?->status);
        $this->travel(6)->minutes();
        CarbonImmutable::setTestNow(now());
        $this->assertNull($seqr->runStore($store));

        // Beta replies (to our first email); an out-of-office from Acme is ignored
        $betaMsg = OutreachMessage::where('step', 0)->where('enrollment_id', OutreachEnrollment::where('email', 'sales@beta.example')->value('id'))->value('message_id');
        $this->mail->inbox = [
            ['from' => 'buy@acme.example', 'subject' => 'Automatic reply: Screen protectors', 'in_reply_to' => $first['id'], 'references' => [], 'text' => 'I am away', 'auto' => true, 'date' => null],
            ['from' => 'sales@beta.example', 'subject' => 'Re: Screen protectors for Beta Phones', 'in_reply_to' => $betaMsg, 'references' => [], 'text' => "Yes, send prices please.\n> old text", 'auto' => false, 'date' => null],
        ];
        $this->assertSame(['replied' => 1, 'bounced' => 0], app(ReplyChecker::class)->check($store));
        $this->assertSame('replied', OutreachEnrollment::where('email', 'sales@beta.example')->value('status'));
        $this->assertSame('replied', $beta->fresh()->status);
        $this->assertStringContainsString('Yes, send prices please.', $beta->activities()->latest('id')->value('body'));
        $this->assertSame('active', OutreachEnrollment::where('email', 'buy@acme.example')->value('status'));

        // 3 days later (a Friday, working hours; follow-ups get up to 90 minutes of jitter): Acme's follow-up in the same thread, none for Beta
        $this->travel(3)->days();
        $this->travel(2)->hours();
        CarbonImmutable::setTestNow(now());
        $this->assertSame('sent', $seqr->runStore($store)?->status);
        $follow = end($this->mail->sent);
        $this->assertSame('buy@acme.example', $follow['to']);
        $this->assertSame('Re: Screen protectors for Acme Cell', $follow['subject']);
        $this->assertSame($first['id'], $follow['thread']['in_reply_to']);
        $this->assertSame('completed', OutreachEnrollment::where('email', 'buy@acme.example')->value('status'));
        $this->travel(1)->hours();
        CarbonImmutable::setTestNow(now());
        $this->assertNull($seqr->runStore($store));   // Beta replied: no follow-up

        // Admin lists and previews
        $this->as()->getJson("/api/admin/outreach/sequences/{$seq['id']}/enrollments")->assertOk()->assertJsonPath('data.0.status', 'replied');
        $this->as()->getJson('/api/admin/outreach')->assertOk()->assertJsonPath('sequences.0.sent', 3)->assertJsonPath('sequences.0.reply_rate', 50);
    }

    public function test_working_hours_weekends_bounces_and_unsubscribe(): void
    {
        $this->mailbox();
        $seq = $this->sequence();
        $lead = $this->lead('Night Shop', 'JP', ['buy@night.example']);
        $other = $this->lead('Bounce Co', 'US', ['nobody@bounce.example']);
        $this->as()->postJson("/api/admin/outreach/sequences/{$seq['id']}/enroll", ['ids' => [$lead->id, $other->id]])->assertOk()->assertJsonPath('enrolled', 2);
        $store = $this->store();
        $seqr = app(Sequencer::class);

        // 10:00 in Chicago = 01:00 in Tokyo: only the US lead is sent now
        $seqr->runStore($store);
        $this->assertSame(['nobody@bounce.example'], array_column($this->mail->sent, 'to'));
        $this->assertFalse($seqr->workingHours('US', 9, 17, CarbonImmutable::parse('2026-11-28 16:00:00')));   // Saturday

        // The US address bounces
        $this->mail->inbox = [['from' => 'mailer-daemon@googlemail.com', 'subject' => 'Delivery Status Notification (Failure)', 'in_reply_to' => null, 'references' => [],
            'text' => "Your message wasn't delivered to nobody@bounce.example because the address couldn't be found.", 'auto' => false, 'date' => null]];
        $this->assertSame(1, app(ReplyChecker::class)->check($store)['bounced']);
        $this->assertSame('bounced', OutreachEnrollment::where('email', 'nobody@bounce.example')->value('status'));
        $this->assertTrue(OutreachSuppression::blocks('nobody@bounce.example'));

        // A send error with 550 also counts as a bounce
        $this->mail->fail = '550 5.1.1 User unknown';
        $this->travel(9)->hours();   // 01:00 UTC = 10:00 Wednesday in Tokyo
        CarbonImmutable::setTestNow(now());
        $seqr->runStore($store);
        $this->assertSame('bounced', OutreachEnrollment::where('email', 'buy@night.example')->value('status'));
        $this->mail->fail = null;

        // Unsubscribe: the link shows a button; mail apps' one-click POST works without a CSRF token
        $e = OutreachEnrollment::where('email', 'buy@night.example')->first();
        $e->forceFill(['status' => 'active'])->save();
        OutreachSuppression::where('email', 'buy@night.example')->delete();
        app(CurrentStore::class)->set(null);
        $this->get(self::HOST.'/outreach/unsubscribe/'.$e->token)->assertOk()->assertSee('Unsubscribe');
        $this->post(self::HOST.'/outreach/unsubscribe/'.$e->token, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee("You're unsubscribed", false);
        $this->store();
        $this->assertSame('unsubscribed', $e->fresh()->status);
        $this->assertTrue(OutreachSuppression::blocks('buy@night.example'));
        $this->get(self::HOST.'/outreach/unsubscribe/'.str_repeat('x', 40))->assertNotFound();
    }

    public function test_ai_first_email_and_follow_ups_in_the_recipients_language(): void
    {
        $this->mailbox();
        $this->as()->putJson('/api/admin/lead-finder/settings', ['anthropic_key' => 'sk-ant-test'])->assertOk();
        $this->app->instance(\App\LeadFinder\LeadAi::class, new class extends \App\LeadFinder\LeadAi
        {
            public int $calls = 0;

            public function outreach(Store $store, Lead $lead, ?string $language = null): array
            {
                $this->calls++;

                return ['language' => 'es', 'subject' => 'Micas para Tienda Sol', 'body' => 'Hola, somos fabricantes de micas.', 'subject_zh' => '', 'body_zh' => ''];
            }

            public function json(Store $store, string $prompt, array $schema, string $effort, int $maxTokens): array
            {
                $this->calls++;

                return ['emails' => [['subject' => '', 'body' => 'Hola {{first_name}}, ¿pudiste ver mi correo?']]];
            }
        });
        $seq = $this->sequence(['ai_first' => true]);
        $lead = $this->lead('Tienda Sol', 'MX', ['compras@sol.example']);
        $this->as()->postJson("/api/admin/outreach/sequences/{$seq['id']}/enroll", ['ids' => [$lead->id]])->assertOk();

        // The preview writes the first email once; sending uses the same text
        $id = OutreachEnrollment::value('id');
        $preview = $this->as()->getJson("/api/admin/outreach/enrollments/{$id}/preview")->assertOk()->json('data');
        $this->assertSame('Micas para Tienda Sol', $preview[0]['subject']);
        $this->assertStringContainsString('Hola, somos fabricantes de micas.', $preview[0]['body']);
        $this->assertStringContainsString('Si prefieres no recibir más correos nuestros:', $preview[0]['body']);   // footer in Spanish
        $this->assertSame('Re: Micas para Tienda Sol', $preview[1]['subject']);
        $this->assertStringStartsWith('Hola Tienda Sol, ¿pudiste ver mi correo?', $preview[1]['body']);
        $calls = app(\App\LeadFinder\LeadAi::class)->calls;

        $store = $this->store();
        app(Sequencer::class)->runStore($store);   // 10:00 Mexico City
        $this->assertSame('Micas para Tienda Sol', $this->mail->sent[0]['subject']);
        $this->assertSame($calls, app(\App\LeadFinder\LeadAi::class)->calls);   // not written again
    }

    public function test_auto_enrol_and_daily_repeating_searches(): void
    {
        $this->mailbox();
        $seq = $this->sequence(['auto' => ['enabled' => true, 'min_score' => 60, 'segments' => ['wholesale'], 'countries' => []]]);
        $hot = $this->lead('Hot Buyer', 'US', ['hi@hot.example'], [], 80);
        $cold = $this->lead('Cold Buyer', 'US', ['hi@cold.example'], [], 30);
        $this->assertSame(1, app(Sequencer::class)->autoEnroll($this->store(), collect([$hot, $cold]), 'leads'));
        $this->assertSame(['hi@hot.example'], OutreachEnrollment::pluck('email')->all());

        Queue::fake();
        $this->store();
        $s = LeadSearch::create(['name' => 'US · cases', 'industry' => 'custom', 'keywords' => ['phone case wholesale'], 'country' => 'US', 'cities' => [], 'source' => 'osm', 'status' => 'done']);
        $this->as()->patchJson("/api/admin/lead-searches/{$s->id}/repeat", ['repeat_daily' => true])->assertOk()->assertJsonPath('data.repeat_daily', true);
        $this->artisan('lumee:repeat-lead-searches')->assertSuccessful();
        $this->artisan('lumee:repeat-lead-searches')->assertSuccessful();   // not twice the same day
        $this->assertSame(2, LeadSearch::withoutGlobalScope('store')->count());
        Queue::assertPushed(\App\LeadFinder\RunLeadSearch::class, 1);
    }
}

/** Records what would be sent; serves a scripted inbox. */
class FakeMailbox extends Mailbox
{
    public array $sent = [];

    public array $inbox = [];

    public ?string $fail = null;

    public function send(Store $store, string $to, ?string $toName, string $subject, string $body, array $thread = []): string
    {
        if ($this->fail) {
            throw new \RuntimeException($this->fail);
        }
        $id = 'm'.(count($this->sent) + 1).'@factory.example';
        $this->sent[] = compact('to', 'toName', 'subject', 'body', 'thread', 'id');

        return $id;
    }

    public function inbox(Store $store, \DateTimeInterface $since): array
    {
        return $this->inbox;
    }

    public function test(Store $store, string $service): void {}
}
