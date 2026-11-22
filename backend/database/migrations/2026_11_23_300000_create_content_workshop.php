<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content workshop (内容工坊): quality score per product, a history of title/description
 * versions (before every AI rewrite or restore), and standardized images that keep their original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedTinyInteger('content_score')->nullable();
            $table->timestamp('content_scored_at')->nullable();
            $table->index(['store_id', 'content_score']);
        });

        Schema::create('product_content_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 8)->nullable();          // null = the store's default language (product fields)
            $table->string('title')->nullable();
            $table->longText('description')->nullable();
            $table->json('seo')->nullable();
            $table->string('reason', 20);                     // before_ai | before_restore | before_edit
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['product_id', 'id']);
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->string('original_path')->nullable();      // the file before standardizing (revertable)
            $table->json('processed')->nullable();            // {ratio, background, removed_background, at}
        });
    }

    public function down(): void
    {
        Schema::table('product_images', fn (Blueprint $t) => $t->dropColumn(['original_path', 'processed']));
        Schema::dropIfExists('product_content_versions');
        Schema::table('products', function (Blueprint $t) {
            $t->dropIndex(['store_id', 'content_score']);
            $t->dropColumn(['content_score', 'content_scored_at']);
        });
    }
};
