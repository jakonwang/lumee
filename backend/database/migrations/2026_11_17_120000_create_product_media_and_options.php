<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // Nullable: images are uploaded before the product is first saved, then attached
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('disk', 32);
            $table->string('path');
            $table->string('alt')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'position']);
        });

        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);                 // e.g. "Material", "Size"
            $table->json('values');                     // ["18K Gold", "Sterling Silver"]
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('image_id')->nullable()->after('product_id')
                ->constrained('product_images')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('image_id');
        });
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('product_images');
    }
};
