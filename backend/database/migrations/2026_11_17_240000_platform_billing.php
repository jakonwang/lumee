<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform billing: one subscription per store, platform invoices (what merchants pay Lumée),
 * a transaction-fee ledger, and platform-admin users for the ops console.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('password');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->string('suspended_reason', 255)->nullable()->after('status');
            $table->timestamp('trial_reminded_at')->nullable()->after('trial_ends_at');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('plan', 32);
            $table->string('interval', 8);                         // month | year
            $table->string('status', 16);                          // active | past_due | canceled
            $table->string('provider', 16);                        // stripe | test | manual (granted by ops)
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('past_due_since')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('note', 255)->nullable();               // e.g. why ops granted it
            $table->timestamps();
        });

        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32)->unique();                // LM-2026-000001
            $table->string('kind', 16);                            // subscription | fees
            $table->string('description');
            $table->unsignedBigInteger('amount');                  // USD cents
            $table->char('currency', 3)->default('USD');
            $table->string('status', 16);                          // paid | open | void
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->json('lines')->nullable();
            $table->string('provider_ref')->nullable()->unique();  // Stripe invoice / invoice item id
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'created_at']);
        });

        // +fee when an order is paid on a paid plan, −credit when it's refunded. Billed monthly.
        Schema::create('platform_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);                            // fee | credit
            $table->unsignedInteger('rate_bp');
            $table->bigInteger('base_amount');                     // order amount the rate applies to (store currency)
            $table->bigInteger('amount');                          // fee in store currency minor units (credit < 0)
            $table->char('currency', 3);
            $table->foreignId('invoice_id')->nullable()->constrained('platform_invoices')->nullOnDelete();
            $table->timestamps();
            $table->index(['store_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fees');
        Schema::dropIfExists('platform_invoices');
        Schema::dropIfExists('subscriptions');
        Schema::table('stores', fn (Blueprint $t) => $t->dropColumn(['suspended_reason', 'trial_reminded_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_platform_admin'));
    }
};
