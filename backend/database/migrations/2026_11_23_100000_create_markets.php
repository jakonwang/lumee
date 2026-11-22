<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Markets: one store selling to several countries, each with its own currency (charged at checkout,
 * not just displayed), languages, URL (/{lang}-{handle}/…), prices and product range.
 * The store itself is the primary market (no prefix, store currency); these rows are the extra ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('markets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('handle', 12);                       // URL suffix: /en-us/, /es-mx/
            $table->json('countries');                          // ["US"] or ["DE","FR",…]
            $table->string('currency', 3);
            $table->string('default_locale', 8);
            $table->json('locales');                            // languages offered in this market (incl. default)
            $table->decimal('price_adjustment', 6, 2)->default(0);   // % added to converted prices (+15 = 15% higher)
            $table->string('rounding', 10)->default('0.99');
            $table->decimal('exchange_rate', 18, 8)->nullable();     // manual rate (1 store currency = x); null = daily rate
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['store_id', 'handle']);
        });

        // Fixed prices per market (otherwise converted from the store price)
        Schema::create('market_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedBigInteger('price')->nullable();
            $table->unsignedBigInteger('compare_at_price')->nullable();
            $table->timestamps();
            $table->unique(['market_id', 'variant_id']);
        });

        // Products not sold in a market (everything is sold everywhere unless excluded)
        Schema::create('market_product_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['market_id', 'product_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('market_id')->nullable()->after('locale')->constrained()->nullOnDelete();
            // 1 store currency = exchange_rate order currency (1 for orders in the store currency).
            // Reports divide by it to add up orders from different markets.
            $table->decimal('exchange_rate', 18, 8)->default(1)->after('market_id');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->foreignId('market_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('carts', fn (Blueprint $t) => $t->dropConstrainedForeignId('market_id'));
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('market_id');
            $t->dropColumn('exchange_rate');
        });
        Schema::dropIfExists('market_product_exclusions');
        Schema::dropIfExists('market_prices');
        Schema::dropIfExists('markets');
    }
};
