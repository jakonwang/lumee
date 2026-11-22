<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign versions of the store design that go live on their own: at starts_at the campaign
 * design is published (what was live is kept in "previous"), at ends_at the previous design comes
 * back. Versions prepared automatically from the shopping calendar wait for the merchant (review).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_theme_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('event_key', 40)->nullable();          // shopping calendar event, e.g. black_friday-2026
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->json('config');                               // main site: full config; market: its layer
            $table->json('previous')->nullable();                 // what was live when it started
            $table->string('status', 12)->default('scheduled');   // review | scheduled | active | done | cancelled
            $table->string('source', 10)->default('manual');      // manual | auto
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['status', 'starts_at']);
            $table->index(['store_theme_id', 'market_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_schedules');
    }
};
