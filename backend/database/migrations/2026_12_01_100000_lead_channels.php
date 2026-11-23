<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead finder channels: a search can look through Google web results and public social profiles
 * (Facebook pages, Instagram, LinkedIn companies, TikTok) as well as maps. Leads remember the
 * audience size of the profile they were found on, and people found at the company's domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_searches', function (Blueprint $table) {
            $table->json('channels')->nullable()->after('source');   // web | facebook | instagram | linkedin | tiktok
        });
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedInteger('followers')->nullable()->after('reviews_count');
            $table->json('contacts')->nullable()->after('emails');   // people at the company [{email, name, position, type}] (Hunter.io)
        });
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn(['followers', 'contacts']));
        Schema::table('lead_searches', fn (Blueprint $table) => $table->dropColumn('channels'));
    }
};
