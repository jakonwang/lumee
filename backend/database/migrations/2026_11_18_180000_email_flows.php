<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Automated email flows: welcome series, post-purchase, win-back
        Schema::create('email_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                       // welcome | post_purchase | win_back
            $table->boolean('enabled')->default(false);
            $table->json('settings')->nullable();             // win_back_days, stop_on_order
            $table->timestamps();
            $table->unique(['store_id', 'type']);
        });

        // Each flow step is a campaign row (same editor, tracking, unsubscribe and stats)
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->foreignId('email_flow_id')->nullable()->after('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('flow_position')->nullable()->after('email_flow_id');
            $table->unsignedInteger('delay_hours')->default(0)->after('flow_position');   // after the trigger / previous step
            $table->string('discount_code', 40)->nullable()->after('product_ids');
        });

        Schema::create('email_flow_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('next_position')->default(1);
            $table->timestamp('next_send_at')->nullable();
            $table->string('status', 12)->default('active');  // active | completed | stopped
            $table->string('stop_reason', 30)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['email_flow_id', 'customer_id']);
            $table->index(['status', 'next_send_at']);
        });

        Schema::table('email_campaign_recipients', function (Blueprint $table) {
            $table->index(['store_id', 'sent_at']);   // monthly send count per store (plan limit)
        });
    }

    public function down(): void
    {
        Schema::table('email_campaign_recipients', fn (Blueprint $t) => $t->dropIndex(['store_id', 'sent_at']));
        Schema::dropIfExists('email_flow_enrollments');
        Schema::table('email_campaigns', function (Blueprint $t) {
            $t->dropConstrainedForeignId('email_flow_id');
            $t->dropColumn(['flow_position', 'delay_hours', 'discount_code']);
        });
        Schema::dropIfExists('email_flows');
    }
};
