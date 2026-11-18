<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Automatic discounts (no code needed): timed sales, spend-more-save-more tiers, bundles
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('type', 16);                       // sale | tiered | bundle
            $table->string('value_type', 16)->default('percentage');   // percentage | fixed
            $table->json('config');                           // per type, see App\Promotions\Promotions
            $table->boolean('combines_with_codes')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedBigInteger('discount_total')->default(0);   // order-level savings given (minor units)
            $table->timestamps();
            $table->index(['store_id', 'enabled', 'type']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // What made up discount_total: [{type: code|promotion, id?, title, amount}]
            $table->json('discount_lines')->nullable()->after('discount_total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('discount_lines'));
        Schema::dropIfExists('promotions');
    }
};
