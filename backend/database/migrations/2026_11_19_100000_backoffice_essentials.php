<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Back-office essentials: customer tags + notes, URL redirects, an inventory change log,
 * and where an order came from (storefront, admin-created, API).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('phone');
            $table->text('note')->nullable()->after('tags');
        });

        Schema::create('url_redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('path', 500);          // normalised: leading "/", no query, no trailing "/"
            $table->string('target', 500);        // site path or http(s) URL
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'path']);
        });

        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id');
            $table->integer('delta');
            $table->integer('quantity_after');
            $table->string('reason', 20);         // created | edit | manual | order | refund | cancel | import | api
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['store_id', 'variant_id', 'id']);
            $table->index(['store_id', 'product_id', 'id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('payment_gateway');   // null/web = storefront, admin, api
            $table->unsignedBigInteger('created_by')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['source', 'created_by']));
        Schema::dropIfExists('inventory_adjustments');
        Schema::dropIfExists('url_redirects');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['tags', 'note']));
    }
};
