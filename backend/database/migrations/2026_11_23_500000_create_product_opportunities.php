<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product research radar (选品雷达): product ideas (a keyword in a market) with the evidence behind
 * them — the store's own search and sales signals, search trends, shopping competition and prices —
 * a score, and an AI market analysis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('market_id')->default(0);       // 0 = the store's default site
            $table->string('keyword', 120);
            $table->string('source', 16);                              // search_gap | top_search | hot_type | manual
            $table->string('status', 12)->default('new');              // new | watching | sourcing | testing | won | dropped
            $table->json('signals')->nullable();                       // {site: {...}, trend: {...}, shopping: {...}}
            $table->json('scores')->nullable();                        // {demand, trend, competition, price, fit, total}
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('ai')->nullable();                            // market analysis
            $table->string('note', 500)->nullable();
            $table->timestamp('enriched_at')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'market_id', 'keyword']);
            $table->index(['store_id', 'status', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_opportunities');
    }
};
