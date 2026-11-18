<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('handle');
            $table->text('description')->nullable();
            $table->foreignId('image_id')->nullable()->constrained('store_media')->nullOnDelete();
            $table->string('sort_order', 20)->default('manual');   // manual | newest | price_asc | price_desc | title
            $table->boolean('published')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'handle']);
        });

        Schema::create('collection_product', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->primary(['collection_id', 'product_id']);
            $table->index('product_id');
        });

        // Navigation menus (main header menu, footer menu). Items are a small tree stored as JSON;
        // links point at records by id and are resolved to URLs at render time, so renaming a
        // collection's handle never breaks the menu.
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('handle', 40);       // main | footer
            $table->string('title');
            $table->json('items');
            $table->timestamps();
            $table->unique(['store_id', 'handle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
        Schema::dropIfExists('collection_product');
        Schema::dropIfExists('collections');
    }
};
