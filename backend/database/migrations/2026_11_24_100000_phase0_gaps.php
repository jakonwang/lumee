<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closing the gaps left by the markets plan:
 *  - orders.payment_fee: a fee for the payment method (cash on delivery), order currency
 *  - wholesale_catalogs.order_key + orders.wholesale_catalog_id: distributors place orders through the
 *    catalog's API with a separate key (the catalog link itself may be forwarded around)
 *  - cart_items.gift / order_items.gift: who a purchased gift card goes to {email, name, message}
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_fee')->default(0)->after('duty_total');
            $table->unsignedBigInteger('wholesale_catalog_id')->nullable()->index();
        });
        Schema::table('wholesale_catalogs', function (Blueprint $table) {
            $table->char('order_key', 48)->nullable()->unique();   // null = ordering through the API is off
        });
        foreach (['cart_items', 'order_items'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->json('gift')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['cart_items', 'order_items'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('gift'));
        }
        Schema::table('wholesale_catalogs', fn (Blueprint $t) => $t->dropColumn('order_key'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['payment_fee', 'wholesale_catalog_id']));
    }
};
