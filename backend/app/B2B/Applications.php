<?php

namespace App\B2B;

use App\Models\B2bApplication;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerToken;
use App\Models\Store;
use App\Notifications\B2BMail;
use App\Notifications\NotificationSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Wholesale sign-up: businesses apply on the storefront; approving creates the company, links (or
 * creates) the buyer's account, and emails them — with a "set your password" link if they're new.
 */
class Applications
{
    public function apply(Store $store, array $data): B2bApplication
    {
        $email = Str::lower(trim($data['email']));
        if (B2bApplication::where('email', $email)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['email' => __('We already have an application from this email. We’ll be in touch soon.')]);
        }
        $app = B2bApplication::create([...$data, 'email' => $email, 'status' => 'pending']);
        $this->mail($store, 'application_received', 'merchant', ['application' => $app]);

        return $app;
    }

    public function approve(B2bApplication $app, array $options = []): Company
    {
        if ($app->status !== 'pending') {
            throw ValidationException::withMessages(['status' => __('这个申请已经处理过了')]);
        }
        $store = Store::find($app->store_id);
        $s = B2B::settings($store);

        [$company, $customer, $isNew] = DB::transaction(function () use ($app, $options, $s) {
            $company = Company::create([
                'name' => $app->company_name, 'tax_id' => $app->tax_id, 'tag' => $options['tag'] ?? $s['tag'],
                'payment_terms' => ($options['payment_terms'] ?? $s['terms']) ?: null, 'tax_exempt' => (bool) ($options['tax_exempt'] ?? false),
            ]);
            [$first, $last] = array_pad(explode(' ', trim($app->contact_name), 2), 2, null);
            $customer = Customer::firstOrNew(['email' => $app->email]);
            $isNew = ! $customer->hasAccount();
            $customer->fill(['first_name' => $customer->first_name ?: $first, 'last_name' => $customer->last_name ?: $last, 'phone' => $customer->phone ?: $app->phone]);
            $customer->forceFill(['company_id' => $company->id, 'company_role' => 'admin'])->save();
            $app->forceFill(['status' => 'approved', 'company_id' => $company->id, 'customer_id' => $customer->id, 'decided_at' => now()])->save();

            return [$company, $customer, $isNew];
        });

        $link = $isNew
            ? $store->storefrontUrl().'/account/reset/'.CustomerToken::issue($customer, 'reset', 60 * 24 * 7)
            : $store->storefrontUrl().'/account/login';
        $this->mail($store, 'application_approved', 'buyer', ['application' => $app, 'link' => $link, 'isNew' => $isNew], $app->email);

        return $company;
    }

    public function reject(B2bApplication $app, ?string $reason): void
    {
        if ($app->status !== 'pending') {
            return;
        }
        $app->forceFill(['status' => 'rejected', 'decided_at' => now()])->save();
        $this->mail(Store::find($app->store_id), 'application_rejected', 'buyer', ['application' => $app, 'reason' => $reason], $app->email);
    }

    private function mail(Store $store, string $type, string $to, array $data, ?string $address = null): void
    {
        try {
            $store->loadMissing('owner');
            $address ??= NotificationSettings::for($store)->get('merchant_email');
            $locale = $to === 'merchant' ? ($store->owner?->locale ?: 'zh_CN') : \App\I18n\Localization::storeDefault($store);
            if ($address) {
                Mail::to($address)->locale($locale)->send(new B2BMail($store, $type, $data));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
