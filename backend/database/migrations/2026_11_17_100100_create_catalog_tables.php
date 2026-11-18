<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('handle');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');  // active | draft | archived
            $table->string('vendor')->nullable();
            $table->string('product_type')->nullable();
            $table->json('tags')->nullable();
            $table->string('featured_image')->nullable();
            $table->json('seo')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'handle']);
            $table->index(['store_id', 'status']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('title')->default('Default');
            $table->string('sku')->nullable();
            // Money is stored as integer minor units (cents) in the store currency
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedBigInteger('compare_at_price')->nullable();
            $table->integer('inventory_quantity')->default(0);
            $table->json('options')->nullable();              // {"Material":"18K Gold","Size":"16in"}
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['store_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
