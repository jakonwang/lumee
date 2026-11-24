<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outreach sequences (开发信序列, App\Outreach): a first email and follow-ups sent from the store's own
 * mailbox to leads and creators, paced per day, stopped when they reply, unsubscribe or bounce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('audience', 10)->default('leads');     // leads | creators
            $table->json('steps');                                // [{delay_days, subject, body}] — step 1 may be written by AI per recipient
            $table->boolean('ai_first')->default(true);           // AI writes the first email in the recipient's language
            $table->json('auto')->nullable();                     // auto-enrol: {enabled, min_score, segments[], countries[]}
            $table->string('status', 10)->default('active');      // active | paused
            $table->timestamps();
        });

        Schema::create('outreach_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sequence_id')->constrained('outreach_sequences')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 255);
            $table->string('name', 200)->nullable();
            $table->char('country', 2)->nullable();
            $table->unsignedTinyInteger('step')->default(0);      // next step to send
            $table->string('status', 12)->default('active');      // active | replied | completed | unsubscribed | bounced | stopped | failed
            $table->timestamp('next_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->json('first')->nullable();                    // AI-written first email {subject, body, language}
            $table->string('token', 40)->unique();                // unsubscribe link
            $table->unsignedTinyInteger('failures')->default(0);   // sending errors in a row (3 = give up)
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['sequence_id', 'email']);
            $table->index(['store_id', 'status', 'next_at']);
        });

        Schema::create('outreach_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('outreach_enrollments')->cascadeOnDelete();
            $table->unsignedTinyInteger('step');
            $table->string('subject', 300);
            $table->text('body');
            $table->string('message_id', 255)->nullable()->index();
            $table->string('status', 10);                         // sent | failed
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'sent_at']);
        });

        // Never email these again from this store (unsubscribed, bounced, asked not to be contacted)
        Schema::create('outreach_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('reason', 16);                         // unsubscribed | bounced | manual
            $table->timestamp('created_at')->nullable();
            $table->unique(['store_id', 'email']);
        });

        // Lead searches that run again every day (new leads only: existing ones are recognised)
        Schema::table('lead_searches', function (Blueprint $table) {
            $table->boolean('repeat_daily')->default(false)->after('channels');
            $table->timestamp('repeated_at')->nullable()->after('repeat_daily');
        });
    }

    public function down(): void
    {
        Schema::table('lead_searches', fn (Blueprint $table) => $table->dropColumn(['repeat_daily', 'repeated_at']));
        Schema::dropIfExists('outreach_suppressions');
        Schema::dropIfExists('outreach_messages');
        Schema::dropIfExists('outreach_enrollments');
        Schema::dropIfExists('outreach_sequences');
    }
};
