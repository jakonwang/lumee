<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead finder (获客雷达): searches public business listings for potential B2B buyers,
 * enriches them from their own websites, scores them and tracks the outreach pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('industry', 40);                    // profile key (LeadProfiles)
            $table->json('keywords');                           // search phrases
            $table->string('country', 2);
            $table->json('cities');                             // [] = whole country (Google) / capital (OSM)
            $table->string('source', 12);                       // google | osm
            $table->string('status', 12)->default('queued');    // queued | running | done | failed
            $table->string('stage', 20)->nullable();            // searching | enriching | scoring
            $table->unsignedInteger('found')->default(0);
            $table->unsignedInteger('new_leads')->default(0);
            $table->unsignedInteger('enriched')->default(0);
            $table->unsignedInteger('to_enrich')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'id']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_search_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 12);                       // google | osm | manual
            $table->string('source_id', 191);
            $table->string('name', 200);
            $table->string('category', 120)->nullable();        // listing category as given by the source
            $table->string('segment', 16)->default('other');    // wholesale | repair | retail | ecommerce | brand | other
            $table->string('country', 2)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('address', 300)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('website', 300)->nullable();
            $table->string('domain', 191)->nullable();
            $table->json('emails')->nullable();                 // ["sales@x.com"]
            $table->json('phones')->nullable();                 // extra numbers found on the website
            $table->json('socials')->nullable();                // {facebook, instagram, linkedin, whatsapp, ...}
            $table->text('summary')->nullable();                // what the company does (site description / AI)
            $table->text('site_text')->nullable();              // visible text of their site (≤ 4000 chars) for scoring / AI
            $table->json('signals')->nullable();                // ["shopify", "wholesale", "b2b"]
            $table->decimal('rating', 2, 1)->nullable();
            $table->unsignedInteger('reviews_count')->nullable();
            $table->unsignedTinyInteger('score')->default(0);   // 0–100
            $table->json('score_reasons')->nullable();
            $table->boolean('ai_scored')->default(false);
            $table->string('status', 12)->default('new');       // new | contacted | replied | qualified | won | lost
            $table->boolean('starred')->default(false);
            $table->text('note')->nullable();
            $table->string('enrich_status', 12)->default('pending');   // pending | done | failed | skipped
            $table->string('enrich_error', 200)->nullable();
            $table->timestamp('enriched_at')->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'source', 'source_id']);
            $table->index(['store_id', 'score']);
            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'domain']);
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                         // status | note | email | call | created
            $table->text('body')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->index(['lead_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lead_searches');
    }
};
