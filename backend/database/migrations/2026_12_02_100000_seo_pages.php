<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO landing pages (App\Marketing\SeoPages): one page per market and search keyword, in the market's
 * language, listing the products that match it. Generated from product ideas in the research radar
 * (local search demand) or added by hand. Served at {site prefix}/s/{slug}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_id')->nullable()->constrained()->cascadeOnDelete();   // null = the default site
            $table->string('locale', 10);
            $table->string('keyword', 120);
            $table->string('slug', 140);
            $table->string('title', 160);
            $table->string('meta_description', 300)->nullable();
            $table->string('heading', 160);
            $table->text('intro')->nullable();                     // HTML: p, ul, li, strong, h2
            $table->json('faq')->nullable();                       // [{q, a}]
            $table->json('product_ids')->nullable();               // pinned order; null = matched automatically
            $table->string('status', 10)->default('draft');        // draft | published
            $table->boolean('ai')->default(false);
            $table->unsignedBigInteger('opportunity_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'slug']);
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_pages');
    }
};
