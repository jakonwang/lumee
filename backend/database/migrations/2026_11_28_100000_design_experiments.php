<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data-driven store design (phase E):
 *  - analytics_sessions remember their market and, during a design A/B test, which version they saw
 *  - design_experiments: the live design (A) against a draft (B) for the main site or one market
 *  - design_insights: the weekly look at each market's numbers, with findings and suggestions
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('market_id')->nullable()->after('store_id');
            $table->unsignedBigInteger('design_experiment_id')->nullable();
            $table->char('design_arm', 1)->nullable();
            $table->index(['store_id', 'market_id', 'started_at']);
            $table->index('design_experiment_id');
        });

        Schema::create('design_experiments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_theme_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('variant');                              // B: main site → full config; market → its layer
            $table->string('status', 10)->default('running');     // running | finished | stopped
            $table->char('winner', 1)->nullable();
            $table->string('note', 200)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['store_theme_id', 'market_id', 'status']);
        });

        Schema::create('design_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('week');                                  // Monday of the week looked at
            $table->json('metrics');
            $table->json('findings');                              // rule-based: [{level, code, message}]
            $table->json('suggestions')->nullable();               // Claude: [{title, detail}]
            $table->timestamps();
            $table->unique(['store_id', 'market_id', 'week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_insights');
        Schema::dropIfExists('design_experiments');
        Schema::table('analytics_sessions', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'market_id', 'started_at']);
            $table->dropIndex(['design_experiment_id']);
            $table->dropColumn(['market_id', 'design_experiment_id', 'design_arm']);
        });
    }
};
