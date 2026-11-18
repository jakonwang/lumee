<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant content translations: one row per (resource, language, field), e.g.
 * (product 12, fr, title) = "Bague en quartz rose". Missing rows fall back to the default language.
 * Orders and customers remember the shopper's language (emails are sent in it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);              // product | option | collection | page | menu | theme | shipping | store
            $table->unsignedBigInteger('resource_id');
            $table->string('locale', 10);
            $table->string('field', 191);            // title, description, seo_title, value:White, item:ab12cd…
            $table->text('value');
            $table->timestamps();
            $table->unique(['store_id', 'type', 'resource_id', 'locale', 'field'], 'translations_unique');
            $table->index(['store_id', 'locale', 'type']);
        });

        Schema::table('orders', fn (Blueprint $t) => $t->string('locale', 10)->nullable()->after('currency'));
        Schema::table('customers', fn (Blueprint $t) => $t->string('locale', 10)->nullable()->after('phone'));
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('locale'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('locale'));
        Schema::dropIfExists('translations');
    }
};
