<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // Signs the uninstall callback (the client secret itself is only stored hashed)
            $table->string('signing_secret', 64)->nullable()->after('client_secret_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('apps', fn (Blueprint $t) => $t->dropColumn('signing_secret'));
    }
};
