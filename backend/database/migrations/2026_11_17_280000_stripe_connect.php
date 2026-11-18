<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Stripe Connect (Express) account the store is paid into, plus a snapshot of its status
            $table->string('stripe_account_id', 64)->nullable()->unique()->after('currency');
            $table->json('stripe_account')->nullable()->after('stripe_account_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Where a Connect payment went and the platform fee Stripe took at payment time
            $table->string('stripe_account', 64)->nullable()->after('payment_reference');
            $table->unsignedBigInteger('application_fee')->nullable()->after('stripe_account');
        });

        Schema::table('platform_fees', function (Blueprint $table) {
            // Set when the fee was already taken as a Stripe application fee (never billed monthly)
            $table->timestamp('collected_at')->nullable()->after('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('platform_fees', fn (Blueprint $t) => $t->dropColumn('collected_at'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['stripe_account', 'application_fee']));
        Schema::table('stores', function (Blueprint $t) {
            $t->dropUnique(['stripe_account_id']);
            $t->dropColumn(['stripe_account_id', 'stripe_account']);
        });
    }
};
