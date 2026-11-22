<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-and-learn (测款): product views and add-to-carts per market and day, what shoppers search
 * for (including searches with no results), A/B tests of a product's main image or title, and the
 * latest test verdict on each product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_market_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('market_id')->default(0);   // 0 = the store's default site
            $table->date('date');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('carts')->default(0);
            $table->unique(['store_id', 'product_id', 'market_id', 'date']);
            $table->index(['store_id', 'date']);
        });

        Schema::create('search_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('market_id')->default(0);
            $table->date('date');
            $table->string('term', 100);
            $table->unsignedInteger('searches')->default(0);
            $table->unsignedInteger('zero_results')->default(0);   // searches that found nothing
            $table->unsignedInteger('results')->default(0);        // result count of the latest search
            $table->unique(['store_id', 'market_id', 'date', 'term']);
        });

        Schema::create('content_experiments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                    // image | title
            $table->string('b_value', 255);                // the challenger: an image id, or a title
            $table->string('status', 10)->default('running');   // running | finished | stopped
            $table->string('winner', 1)->nullable();       // a | b
            $table->unsignedInteger('a_views')->default(0);
            $table->unsignedInteger('a_carts')->default(0);
            $table->unsignedInteger('b_views')->default(0);
            $table->unsignedInteger('b_carts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'product_id', 'status']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('test_status', 12)->nullable();   // hot | testing | cold | no_traffic
            $table->timestamp('test_evaluated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['test_status', 'test_evaluated_at']));
        Schema::dropIfExists('content_experiments');
        Schema::dropIfExists('search_terms');
        Schema::dropIfExists('product_market_stats');
    }
};
