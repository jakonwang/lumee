<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author', 80);
            $table->string('email')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 150)->nullable();
            $table->text('body');
            $table->json('images')->nullable();          // [{disk, path}]
            $table->string('status', 16)->default('pending');   // pending | published | hidden
            $table->boolean('verified')->default(false);  // the email bought this product
            $table->string('source', 16)->default('storefront');   // storefront | import
            $table->string('locale', 10)->nullable();
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'product_id', 'status']);
            $table->index(['store_id', 'status', 'created_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            // Published reviews, kept in sync for listings, structured data and the feed
            $table->decimal('rating_avg', 3, 2)->nullable()->after('taxable');
            $table->unsignedInteger('rating_count')->default(0)->after('rating_avg');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['rating_avg', 'rating_count']));
        Schema::dropIfExists('product_reviews');
    }
};
