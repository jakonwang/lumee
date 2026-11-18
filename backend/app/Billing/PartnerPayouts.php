<?php

namespace App\Billing;

use App\Models\PartnerPayout;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Developer payouts. Each month, a developer's 80% share of app charges and theme sales is gathered
 * into one payout — only from platform invoices the merchant has actually paid. Below the minimum
 * ($25) it carries over to the next month. Ops pay it outside Lumée (PayPal / bank transfer, using the
 * details the developer saved) and mark it paid with the transfer reference.
 */
class PartnerPayouts
{
    public const MINIMUM = 2500;

    /** Earnings rows for a developer: app charge bills + theme purchases. */
    private function rows(int $userId): array
    {
        $apps = DB::table('app_charge_bills as b')->join('apps as a', 'a.id', '=', 'b.app_id')->leftJoin('platform_invoices as i', 'i.id', '=', 'b.platform_invoice_id')
            ->where('a.user_id', $userId)->select('b.id', 'b.developer_share', 'b.partner_payout_id', 'i.status as invoice_status', 'b.created_at');
        $themes = DB::table('theme_purchases as p')->join('theme_listings as l', 'l.id', '=', 'p.theme_listing_id')->leftJoin('platform_invoices as i', 'i.id', '=', 'p.platform_invoice_id')
            ->where('l.user_id', $userId)->select('p.id', 'p.developer_share', 'p.partner_payout_id', 'i.status as invoice_status', 'p.created_at');

        return ['apps' => $apps, 'themes' => $themes];
    }

    /** What the developer sees: waiting for merchants to pay, ready for the next payout, paid out. @return array<string,int> */
    public function balance(int $userId): array
    {
        $out = ['pending' => 0, 'available' => 0, 'paid' => 0];
        foreach ($this->rows($userId) as $q) {
            /** @var Builder $q */
            foreach ((clone $q)->get() as $r) {
                $key = $r->partner_payout_id ? 'paid' : ($r->invoice_status === 'paid' ? 'available' : 'pending');
                $out[$key] += (int) $r->developer_share;
            }
        }
        $out['paid'] = (int) PartnerPayout::where('user_id', $userId)->where('status', 'paid')->sum('amount');

        return $out;
    }

    /** Monthly: one payout per developer for everything paid and not yet paid out. Returns how many were created. */
    public function run(string $period): int
    {
        $userIds = DB::table('apps')->pluck('user_id')->merge(DB::table('theme_listings')->pluck('user_id'))->unique();
        $n = 0;
        foreach ($userIds as $userId) {
            if (PartnerPayout::where('user_id', $userId)->where('period', $period)->exists()) {
                continue;
            }
            $ready = ['apps' => [], 'themes' => []];
            $total = 0;
            foreach ($this->rows($userId) as $kind => $q) {
                foreach ((clone $q)->whereNull($kind === 'apps' ? 'b.partner_payout_id' : 'p.partner_payout_id')->where('i.status', 'paid')->get() as $r) {
                    $ready[$kind][] = $r->id;
                    $total += (int) $r->developer_share;
                }
            }
            if ($total < self::MINIMUM) {
                continue;   // carries over
            }
            $user = User::find($userId);
            DB::transaction(function () use ($user, $period, $total, $ready, &$n) {
                $payout = PartnerPayout::create([
                    'user_id' => $user->id, 'period' => $period, 'amount' => $total, 'status' => 'pending',
                    'method' => $user->payout_details['method'] ?? null,
                    'details' => $user->payout_details['account'] ?? null,
                ]);
                DB::table('app_charge_bills')->whereIn('id', $ready['apps'])->update(['partner_payout_id' => $payout->id]);
                DB::table('theme_purchases')->whereIn('id', $ready['themes'])->update(['partner_payout_id' => $payout->id]);
                $n++;
            });
        }

        return $n;
    }
}
