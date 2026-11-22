<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deeper localization: per-market theme text/colours and import-duty rules (markets.settings),
 * import duties charged at checkout (orders.duty_total), and a size chart type per product so
 * shoppers see their own size system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->json('settings')->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('duty_total')->default(0)->after('tax_total');   // import duties collected at checkout (order currency)
        });
        Schema::table('products', function (Blueprint $table) {
            $table->string('size_chart', 20)->nullable();   // shoes_women | shoes_men | rings | clothing
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('size_chart'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('duty_total'));
        Schema::table('markets', fn (Blueprint $t) => $t->dropColumn('settings'));
    }
};
