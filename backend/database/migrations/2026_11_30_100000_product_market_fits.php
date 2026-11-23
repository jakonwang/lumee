<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How well each product suits each market (App\Markets\MarketFit): a 0–100 score from the AI judgement,
 * local search trends, the market's own sales and simple rules (plugs, seasons, restricted goods),
 * with the reasons and local tags. Drives "sell where it fits" when publishing and what each
 * country's storefront shows first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_market_fits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->nullable();   // null = no evidence yet
            $table->json('parts')->nullable();                  // {ai, trend, sales, rules} 0–100 each
            $table->json('reasons')->nullable();                // short Chinese reasons, strongest first
            $table->json('tags')->nullable();                   // local tags, e.g. "gift", "summer"
            $table->json('ai')->nullable();                     // last AI judgement {score, reason, tags}
            $table->timestamp('ai_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'market_id']);
            $table->index(['store_id', 'market_id', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_market_fits');
    }
};
