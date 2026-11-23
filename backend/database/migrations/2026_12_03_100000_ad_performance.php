<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ad performance (App\Analytics\AdReport): which ad platform a visit came from, and what the ads cost
 * per day, platform, market and campaign (imported from the platforms' CSV exports or typed in),
 * so spend can be set against the orders and revenue it brought.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_sessions', function (Blueprint $table) {
            $table->string('ad_platform', 12)->nullable()->after('source');   // google | meta | tiktok | bing | pinterest | snapchat
        });

        Schema::create('ad_spend', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('platform', 12);
            $table->foreignId('market_id')->nullable()->constrained()->nullOnDelete();   // null = not split by country
            $table->string('campaign', 150)->default('');                                 // '' = the whole account
            $table->bigInteger('spend');                     // store currency, minor units
            $table->char('currency', 3);                     // what the platform billed in
            $table->bigInteger('amount');                    // in that currency, minor units
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->string('source', 8)->default('manual');  // manual | import
            $table->timestamps();
            $table->unique(['store_id', 'date', 'platform', 'market_id', 'campaign'], 'ad_spend_line');
            $table->index(['store_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_spend');
        Schema::table('analytics_sessions', fn (Blueprint $table) => $table->dropColumn('ad_platform'));
    }
};
