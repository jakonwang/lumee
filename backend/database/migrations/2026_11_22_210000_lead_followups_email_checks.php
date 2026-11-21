<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lead finder: follow-up reminders and per-email deliverability checks. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('next_follow_up_at')->nullable()->after('contacted_at');
            $table->json('email_checks')->nullable()->after('emails');   // {"sales@x.com": {"mx": true, "kind": "role", "guessed": false}}
            $table->index(['store_id', 'next_follow_up_at']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'next_follow_up_at']);
            $table->dropColumn(['next_follow_up_at', 'email_checks']);
        });
    }
};
