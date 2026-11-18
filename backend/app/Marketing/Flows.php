<?php

namespace App\Marketing;

use App\Models\Customer;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailFlow;
use App\Models\EmailFlowEnrollment;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Automated email flows. A flow is a fixed trigger plus up to 5 steps; each step is an
 * EmailCampaign row (flow_position, delay_hours), so it reuses the campaign email, tracking,
 * unsubscribe link and stats.
 *
 *   welcome        a customer subscribes                       (optionally stops once they order)
 *   post_purchase  a customer's first order is paid
 *   win_back       a customer's last order is N days old        (stops once they order again)
 *   birthday       the customer's birthday (once a year)
 *   browse_abandon a signed-in customer viewed a product N hours ago and hasn't ordered since (stops once they order)
 *
 * lumee:run-flows (every 10 minutes) sends due steps and scans for win-back customers.
 */
class Flows
{
    public const MAX_STEPS = 5;

    /** Starter content, so a merchant can switch a flow on and tweak it. */
    public const TEMPLATES = [
        'welcome' => [
            ['delay_hours' => 0, 'subject' => 'Welcome to {store}', 'heading' => 'Welcome — we’re glad you’re here',
                'body' => "Thanks for joining us! You’ll be the first to hear about new arrivals, restocks and members-only offers.\n\nHere’s a little of what we’re about.", 'button_label' => 'Start shopping', 'button_url' => '/'],
            ['delay_hours' => 72, 'subject' => 'Our customers’ favourites', 'heading' => 'Bestsellers you’ll love',
                'body' => 'A few pieces our customers keep coming back for.', 'button_label' => 'Shop bestsellers', 'button_url' => '/collections/all'],
        ],
        'post_purchase' => [
            ['delay_hours' => 72, 'subject' => 'Thank you for your order', 'heading' => 'Thank you for shopping with us',
                'body' => "We hope you love your order. If you have any questions about care or sizing, just reply to this email.\n\nYou might also like:", 'button_label' => 'Keep shopping', 'button_url' => '/collections/all'],
        ],
        'win_back' => [
            ['delay_hours' => 0, 'subject' => 'We miss you', 'heading' => 'It’s been a while',
                'body' => 'We’ve added lots of new pieces since your last visit. Come take a look.', 'button_label' => 'See what’s new', 'button_url' => '/collections/all'],
        ],
        'birthday' => [
            ['delay_hours' => 0, 'subject' => 'Happy birthday from {store}!', 'heading' => 'Happy birthday!',
                'body' => "Wishing you a wonderful day. Here's a little gift from us — use the code below on your next order.", 'button_label' => 'Treat yourself', 'button_url' => '/collections/all'],
        ],
        'browse_abandon' => [
            ['delay_hours' => 0, 'subject' => 'Still thinking it over?', 'heading' => 'Still thinking it over?',
                'body' => 'We saw you checking this out. It’s still here — but it might not be for long.', 'button_label' => 'Take another look', 'button_url' => '/collections/all'],
        ],
    ];

    public function __construct(private CampaignSender $sender, private SendQuota $quota) {}

    /** The store's three flows, created (switched off, with starter steps) the first time. */
    public function ensureDefaults(Store $store): void
    {
        foreach (array_keys(EmailFlow::TYPES) as $type) {
            $flow = EmailFlow::withoutGlobalScope('store')->firstOrCreate(
                ['store_id' => $store->id, 'type' => $type],
                ['enabled' => false, 'settings' => EmailFlow::DEFAULT_SETTINGS],
            );
            if ($flow->wasRecentlyCreated) {
                foreach (self::TEMPLATES[$type] as $i => $t) {
                    $step = new EmailCampaign([
                        ...$t, 'subject' => str_replace('{store}', $store->name, $t['subject']), 'audience' => 'subscribers', 'status' => 'flow',
                    ]);
                    $step->forceFill(['store_id' => $store->id, 'email_flow_id' => $flow->id, 'flow_position' => $i + 1, 'delay_hours' => $t['delay_hours']])->save();
                }
            }
        }
    }

    // ── Triggers ──────────────────────────────────────────────────────────

    public function onSubscribed(Customer $customer): void
    {
        $this->enroll('welcome', $customer);
    }

    public function onOrderPaid(Order $order): void
    {
        $customer = $order->customer_id ? Customer::withoutGlobalScope('store')->find($order->customer_id) : null;
        if (! $customer) {
            return;
        }
        $paidBefore = Order::withoutGlobalScope('store')->where('store_id', $order->store_id)->where('customer_id', $customer->id)
            ->whereKeyNot($order->id)->whereNotNull('paid_at')->exists();
        if (! $paidBefore) {
            $this->enroll('post_purchase', $customer);   // first paid order only
        }
    }

    /** Customers whose last paid order is older than the flow's window and who haven't been asked since. */
    public function scanWinBack(): int
    {
        $n = 0;
        foreach (EmailFlow::withoutGlobalScope('store')->where('type', 'win_back')->where('enabled', true)->get() as $flow) {
            $cutoff = now()->subDays((int) $flow->setting('win_back_days'));
            $rows = Order::withoutGlobalScope('store')->where('store_id', $flow->store_id)->whereNotNull('paid_at')->whereNotNull('customer_id')
                ->selectRaw('customer_id, MAX(paid_at) as last_paid')->groupBy('customer_id')
                ->havingRaw('MAX(paid_at) <= ?', [$cutoff])->havingRaw('MAX(paid_at) >= ?', [$cutoff->copy()->subDays(365)])
                ->limit(1000)->get();
            foreach ($rows as $row) {
                $customer = Customer::withoutGlobalScope('store')->find($row->customer_id);
                $existing = EmailFlowEnrollment::withoutGlobalScope('store')->where('email_flow_id', $flow->id)->where('customer_id', $row->customer_id)->first();
                // Asked already for this lapse (enrolled after their last order)?
                if ($existing && $existing->started_at >= \Illuminate\Support\Carbon::parse($row->last_paid)) {
                    continue;
                }
                $n += $customer && $this->enroll('win_back', $customer, restart: true) ? 1 : 0;
            }
        }

        return $n;
    }

    public function enroll(string $type, Customer $customer, bool $restart = false): bool
    {
        if (! $customer->accepts_marketing) {
            return false;
        }
        $flow = EmailFlow::withoutGlobalScope('store')->where('store_id', $customer->store_id)->where('type', $type)->where('enabled', true)->first();
        $first = $flow?->steps()->first();
        if (! $first) {
            return false;
        }
        $existing = EmailFlowEnrollment::withoutGlobalScope('store')->where('email_flow_id', $flow->id)->where('customer_id', $customer->id)->first();
        if ($existing && (! $restart || $existing->status === 'active')) {
            return false;   // each customer goes through a flow once (win-back: once per lapse)
        }
        $attrs = [
            'store_id' => $customer->store_id, 'email_flow_id' => $flow->id, 'customer_id' => $customer->id,
            'next_position' => 1, 'next_send_at' => now()->addHours($first->delay_hours), 'status' => 'active',
            'stop_reason' => null, 'started_at' => now(), 'finished_at' => null,
        ];
        $existing ? $existing->forceFill($attrs)->save() : EmailFlowEnrollment::withoutGlobalScope('store')->create($attrs);

        return true;
    }

    // ── Sending ───────────────────────────────────────────────────────────

    /** Customers whose birthday is today (in the store's time zone), once a year. */
    public function scanBirthdays(): int
    {
        $n = 0;
        foreach (EmailFlow::withoutGlobalScope('store')->where('type', 'birthday')->where('enabled', true)->get() as $flow) {
            $store = Store::find($flow->store_id);
            $today = now($store?->timezone ?: 'UTC');
            $customers = Customer::withoutGlobalScope('store')->where('store_id', $flow->store_id)->whereNotNull('birthday')
                ->where('accepts_marketing', true)->get(['id', 'store_id', 'birthday', 'accepts_marketing', 'email'])
                ->filter(fn ($c) => $c->birthday->format('m-d') === $today->format('m-d')
                    || ($c->birthday->format('m-d') === '02-29' && ! $today->isLeapYear() && $today->format('m-d') === '02-28'));
            foreach ($customers as $c) {
                $existing = EmailFlowEnrollment::withoutGlobalScope('store')->where('email_flow_id', $flow->id)->where('customer_id', $c->id)->first();
                if ($existing && $existing->started_at && $existing->started_at->year === $today->year) {
                    continue;   // once a year
                }
                $n += $this->enroll('birthday', $c, restart: true) ? 1 : 0;
            }
        }

        return $n;
    }

    /** Signed-in customers who viewed a product a few hours ago and haven't ordered since (at most once a week). */
    public function scanBrowseAbandon(): int
    {
        $n = 0;
        foreach (EmailFlow::withoutGlobalScope('store')->where('type', 'browse_abandon')->where('enabled', true)->get() as $flow) {
            $hours = max(1, (int) $flow->setting('browse_hours'));
            $customers = Customer::withoutGlobalScope('store')->where('store_id', $flow->store_id)->where('accepts_marketing', true)
                ->whereNotNull('last_viewed_product_id')
                ->whereBetween('last_viewed_at', [now()->subHours($hours + 24), now()->subHours($hours)])->limit(1000)->get();
            foreach ($customers as $c) {
                $ordered = Order::withoutGlobalScope('store')->where('customer_id', $c->id)->where('created_at', '>=', $c->last_viewed_at->copy()->subHour())->exists();
                $recent = EmailFlowEnrollment::withoutGlobalScope('store')->where('email_flow_id', $flow->id)->where('customer_id', $c->id)
                    ->where('started_at', '>=', now()->subDays(7))->exists();
                if (! $ordered && ! $recent) {
                    $n += $this->enroll('browse_abandon', $c, restart: true) ? 1 : 0;
                }
            }
        }

        return $n;
    }

    public function run(): int
    {
        if (Cache::add('flows:win-back-scan', 1, now()->addHours(6))) {
            $this->scanWinBack();
        }
        if (Cache::add('flows:birthday-scan:'.now()->format('Y-m-d-H'), 1, now()->addHours(2))) {
            $this->scanBirthdays();
        }
        $this->scanBrowseAbandon();
        $sent = 0;
        EmailFlowEnrollment::withoutGlobalScope('store')->with('flow', 'customer')
            ->where('status', 'active')->where('next_send_at', '<=', now())
            ->orderBy('id')->chunkById(200, function ($batch) use (&$sent) {
                foreach ($batch as $e) {
                    $sent += $this->process($e) ? 1 : 0;
                }
            });

        return $sent;
    }

    /** Send the enrollment's next step if it's still wanted. Returns true when an email went out. */
    public function process(EmailFlowEnrollment $e): bool
    {
        $flow = $e->flow;
        $customer = $e->customer;
        $stop = match (true) {
            ! $flow || ! $flow->enabled => 'flow_off',
            ! $customer || ! $customer->accepts_marketing => 'unsubscribed',
            in_array($flow->type, ['welcome', 'win_back', 'browse_abandon'], true) && ($flow->type !== 'welcome' || $flow->setting('stop_on_order'))
                && Order::withoutGlobalScope('store')->where('customer_id', $customer->id)->whereNotNull('paid_at')->where('paid_at', '>', $e->started_at)->exists() => 'ordered',
            default => null,
        };
        if ($stop) {
            $e->forceFill(['status' => 'stopped', 'stop_reason' => $stop, 'finished_at' => now()])->save();

            return false;
        }

        $step = $flow->steps()->where('flow_position', $e->next_position)->first();
        if (! $step) {
            $e->forceFill(['status' => 'completed', 'finished_at' => now()])->save();

            return false;
        }

        $store = Store::find($e->store_id);
        if (! $store || ! app(\App\Billing\Billing::class)->isOpen($store)) {
            $e->forceFill(['next_send_at' => now()->addDay()])->save();

            return false;
        }
        if ($this->quota->remaining($store) < 1) {
            $e->forceFill(['next_send_at' => now()->startOfMonth()->addMonth()->addHours(1)])->save();   // try again when the allowance resets

            return false;
        }

        // Claim the step first so overlapping runs can't double-send
        $claimed = EmailFlowEnrollment::withoutGlobalScope('store')->whereKey($e->id)->where('next_position', $e->next_position)->where('status', 'active')
            ->update(['next_position' => $e->next_position + 1]);
        if (! $claimed) {
            return false;
        }

        $recipient = EmailCampaignRecipient::withoutGlobalScope('store')->firstOrNew(['email_campaign_id' => $step->id, 'email' => mb_strtolower($customer->email)]);
        $recipient->forceFill(['store_id' => $store->id, 'customer_id' => $customer->id, 'token' => Str::random(40), 'status' => 'queued', 'error' => null])->save();
        $step->increment('recipients_count');
        try {
            $this->sender->deliver($step, $store, $recipient->setRelation('customer', $customer));
            $recipient->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
            $step->increment('sent_count');
            $ok = true;
        } catch (Throwable $ex) {
            report($ex);
            $recipient->forceFill(['status' => 'failed', 'error' => mb_substr($ex->getMessage(), 0, 300)])->save();
            $step->increment('failed_count');
            $ok = false;
        }

        $next = $flow->steps()->where('flow_position', $e->next_position + 1)->first();
        $e->forceFill($next
            ? ['next_position' => $e->next_position + 1, 'next_send_at' => now()->addHours($next->delay_hours)]
            : ['next_position' => $e->next_position + 1, 'status' => 'completed', 'finished_at' => now()])->save();

        return $ok;
    }
}
