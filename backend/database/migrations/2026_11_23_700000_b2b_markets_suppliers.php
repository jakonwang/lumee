<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2B across markets and the supply side:
 *  - price lists for one market (prices in its currency) or all (converted)
 *  - wholesale catalogs: a shareable link (+ JSON / CSV feed for distributors) with wholesale prices
 *  - suppliers with their own portal link, and purchase orders to restock
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->foreignId('market_id')->nullable()->constrained()->nullOnDelete();   // null = every market (converted)
        });

        Schema::create('wholesale_catalogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->char('token', 40)->unique();
            $table->foreignId('market_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('collection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('show_stock')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('contact_name', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('platform', 20)->nullable();          // 1688 | alibaba | factory | other
            $table->string('note', 500)->nullable();
            $table->char('token', 40)->unique();                 // supplier portal link
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 12)->default('draft');      // draft | sent | confirmed | shipped | received | cancelled
            $table->json('items');                               // [{variant_id, product_id, title, variant_title, sku, quantity, cost}]
            $table->unsignedBigInteger('total')->default(0);     // minor units, store currency
            $table->string('note', 1000)->nullable();            // to the supplier
            $table->string('supplier_note', 1000)->nullable();   // from the supplier
            $table->date('expected_at')->nullable();
            $table->string('tracking', 120)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
        Schema::table('products', fn (Blueprint $t) => $t->dropConstrainedForeignId('supplier_id'));
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('wholesale_catalogs');
        Schema::table('price_lists', fn (Blueprint $t) => $t->dropConstrainedForeignId('market_id'));
    }
};
