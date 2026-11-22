<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product collection (采集): products fetched from supplier / competitor pages land in a review
 * pool (collected_items), get cleaned and priced, then are published to the catalog in bulk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collected_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->char('url_hash', 40);                      // sha1 of the normalized URL (dedupe)
            $table->string('platform', 20)->nullable();         // shopify | woocommerce | page | extension
            $table->string('status', 16)->default('pending');   // pending | ready | failed | published | rejected
            $table->json('data')->nullable();                   // normalized product being edited (see App\Collect\Item)
            $table->json('original')->nullable();               // as collected, to compare / revert
            $table->json('checks')->nullable();                 // cleaning notes, risks, duplicate
            $table->string('error', 500)->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'url_hash']);
            $table->index(['store_id', 'status']);
        });

        // Where a catalog product came from (re-collect / price sync later)
        Schema::create('product_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->char('url_hash', 40);
            $table->string('platform', 20)->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamps();
            $table->index(['store_id', 'url_hash']);
        });

        // Selling price from cost: price = cost (in store currency) × multiplier + add, then a price ending
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('scope', 16)->default('all');        // all | product_type | tag
            $table->string('scope_value', 120)->nullable();
            $table->decimal('multiplier', 8, 3)->default(2.5);
            $table->unsignedInteger('add_amount')->default(0);  // minor units, store currency (e.g. shipping per item)
            $table->decimal('compare_multiplier', 8, 3)->nullable();   // crossed-out price = price × this
            $table->string('rounding', 10)->default('0.99');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedBigInteger('cost')->nullable()->after('compare_at_price');   // minor units, store currency
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', fn (Blueprint $t) => $t->dropColumn('cost'));
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('product_sources');
        Schema::dropIfExists('collected_items');
    }
};
