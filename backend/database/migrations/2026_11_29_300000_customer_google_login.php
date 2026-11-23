<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shoppers can sign in with Google (the Google account id is kept to recognise them next time). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('google_id', 64)->nullable()->after('email');
            $table->index(['store_id', 'google_id']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'google_id']);
            $table->dropColumn('google_id');
        });
    }
};
