<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creators (达人): Instagram / TikTok / YouTube accounts found for a niche and country, scored for a
 * collaboration (audience size, niche match, a way to reach them), contacted, and turned into
 * affiliates with their own code. See App\Marketing\Creators.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 12);                         // instagram | tiktok | youtube
            $table->string('handle', 120);
            $table->string('url', 300);
            $table->string('name', 200);
            $table->string('country', 2)->nullable();
            $table->string('niche', 120)->nullable();               // the keyword it was found with
            $table->unsignedInteger('followers')->nullable();
            $table->json('emails')->nullable();
            $table->string('whatsapp', 60)->nullable();
            $table->string('website', 300)->nullable();
            $table->text('bio')->nullable();
            $table->unsignedTinyInteger('score')->default(0);
            $table->json('score_reasons')->nullable();
            $table->string('status', 12)->default('new');           // new | contacted | replied | agreed | declined
            $table->foreignId('affiliate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 1000)->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'platform', 'handle']);
            $table->index(['store_id', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creators');
    }
};
