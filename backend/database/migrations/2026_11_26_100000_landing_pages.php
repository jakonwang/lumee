<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign landing pages: a page can be text (Markdown, as before) or built from blocks in the
 * theme editor (layout = blocks). The blocks live in the theme config (config.pages.{page id}),
 * so they get drafts, publishing, per-market changes and versions like every other part of the design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->string('layout', 10)->default('text')->after('body');   // text | blocks
        });
    }

    public function down(): void
    {
        Schema::table('pages', fn (Blueprint $t) => $t->dropColumn('layout'));
    }
};
