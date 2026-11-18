<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Theme library: a store can keep several themes (copies, imports), each built on a template
 * (store_themes.theme). stores.theme_id points at the live one; stores.theme stays the live template key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_themes', function (Blueprint $table) {
            $table->string('name', 80)->nullable()->after('theme');
            $table->string('source', 20)->nullable()->after('name');   // template | copy | import | shopify
            $table->index('store_id');                                // keeps the FK indexed once the unique goes
        });
        Schema::table('store_themes', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'theme']);
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->unsignedBigInteger('theme_id')->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('theme_id'));
        Schema::table('store_themes', function (Blueprint $table) {
            $table->unique(['store_id', 'theme']);
            $table->dropIndex(['store_id']);
            $table->dropColumn(['name', 'source']);
        });
    }
};
