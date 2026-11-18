<?php

namespace App\Customers;

use App\Models\Customer;
use App\Models\CustomerToken;
use App\Tenancy\CurrentStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Issues a one-time link and emails it, in the background. The token is created here (in the
 * worker), so its plain value never sits in the jobs table.
 */
class SendCustomerEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const LIFETIME = ['verify' => 60 * 24 * 3, 'reset' => 60];   // minutes

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $customerId, public string $type) {}

    public function handle(CurrentStore $current): void
    {
        $customer = Customer::withoutGlobalScope('store')->with('store')->find($this->customerId);
        if (! $customer || ! $customer->store) {
            return;
        }
        $current->set($customer->store);

        $token = CustomerToken::issue($customer, $this->type, self::LIFETIME[$this->type]);
        $path = $this->type === 'verify' ? '/account/verify/' : '/account/reset/';
        $url = $customer->store->storefrontUrl().\App\Notifications\OrderMail::localePrefix($customer->locale, $customer->store).$path.$token;

        Mail::to($customer->email)->locale($customer->locale ?: \App\I18n\Localization::storeDefault($customer->store))->send(new CustomerAccountMail($customer, $this->type, $url, $customer->store));
    }
}
