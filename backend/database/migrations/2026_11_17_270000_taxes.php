<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taxes: rates by country (optionally state/province), prices tax-inclusive (EU VAT style) or
 * tax added at checkout (US sales tax style). Store-level options live in stores.settings['taxes'].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->char('country', 2);
            $table->string('region', 50)->nullable();     // state / province code, e.g. CA, NY; null = whole country
            $table->string('name', 50);                    // shown to buyers: VAT, GST, Sales tax…
            $table->decimal('rate', 6, 3);                 // percent, e.g. 20.000
            $table->timestamps();
            $table->unique(['store_id', 'country', 'region']);
        });

        Schema::table('products', fn (Blueprint $t) => $t->boolean('taxable')->default(true)->after('status'));

        Schema::table('orders', function (Blueprint $t) {
            $t->boolean('taxes_included')->default(false)->after('tax_total');
            $t->json('tax_lines')->nullable()->after('taxes_included');   // [{name, rate, amount}]
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['taxes_included', 'tax_lines']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('taxable'));
        Schema::dropIfExists('tax_rates');
    }
};
