<?php

use Illuminate\Support\Facades\Schedule;

// Clean up images uploaded in the product editor but never saved to a product.
// Requires the scheduler: `php artisan schedule:work` (dev) or a cron entry for `schedule:run`.
Schedule::command('lumee:prune-images')->dailyAt('03:30');

// Exchange rates for storefront currency conversion (display only — payments use the store currency).
Schedule::command('lumee:exchange-rates')->dailyAt('04:10');

// Platform billing: daily renewals/expiry/reminders; transaction fees invoiced on the 1st of each month.
Schedule::command('lumee:billing-daily')->dailyAt('02:30');
Schedule::command('lumee:bill-fees')->monthlyOn(1, '05:00');

// Abandoned checkout reminders (each store picks its delay; one email per checkout).
Schedule::command('lumee:recover-checkouts')->everyFifteenMinutes()->withoutOverlapping();

// Package tracking fallback (17TRACK also pushes updates to /webhooks/17track).
Schedule::command('lumee:tracking-sync')->hourly()->withoutOverlapping();

// Automated email flows: welcome, post-purchase, win-back.
Schedule::command('lumee:run-flows')->everyTenMinutes()->withoutOverlapping();

// Scheduled email campaigns (the sending itself runs on the queue in batches).
Schedule::command('lumee:send-campaigns')->everyMinute()->withoutOverlapping();

// Review requests a few days after shipping (each store picks the delay).
Schedule::command('lumee:review-requests')->hourly()->withoutOverlapping();

// Subscribe-and-save renewals (charges saved cards; failed charges retry every 3 days).
Schedule::command('lumee:renew-subscriptions')->hourly()->withoutOverlapping();

// B2B: remind buyers once when an on-account (Net terms) order becomes overdue.
Schedule::command('lumee:payment-reminders')->dailyAt('09:00');

// App subscriptions (charges merchants approved for third-party apps), billed every 30 days.
Schedule::command('lumee:bill-app-charges')->dailyAt('05:30');

// Developer payouts: last month's paid marketplace earnings (apps + themes), on the 2nd.
Schedule::command('lumee:partner-payouts')->monthlyOn(2, '06:00');
