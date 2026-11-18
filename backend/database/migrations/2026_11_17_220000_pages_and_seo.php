<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Content pages (About, FAQ, policies) and SEO fields for collections. Products already have `seo`. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('handle');
            $table->longText('body')->nullable();     // Markdown; rendered with raw HTML stripped
            $table->json('seo')->nullable();           // {title, description}
            $table->boolean('published')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'handle']);
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->json('seo')->nullable()->after('published');
        });
    }

    public function down(): void
    {
        Schema::table('collections', fn (Blueprint $t) => $t->dropColumn('seo'));
        Schema::dropIfExists('pages');
    }
};
