<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // When / where the marketing consent last changed (checkout, popup, form, account, unsubscribe)
            $table->string('marketing_source', 20)->nullable()->after('accepts_marketing');
            $table->timestamp('marketing_updated_at')->nullable()->after('marketing_source');
        });

        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 150);
            $table->string('preheader', 150)->nullable();
            $table->string('heading', 150)->nullable();
            $table->text('body')->nullable();                 // Markdown
            $table->string('button_label', 60)->nullable();
            $table->string('button_url', 500)->nullable();
            $table->json('product_ids')->nullable();          // up to 4 featured products
            $table->string('audience', 20)->default('subscribers');   // subscribers | customers | prospects
            $table->string('status', 16)->default('draft');   // draft | scheduled | sending | sent
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->unsignedInteger('unsubscribed_count')->default(0);
            $table->timestamps();
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('email_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('token', 40)->unique();            // open / click / unsubscribe links
            $table->string('status', 10)->default('queued');  // queued | sent | failed
            $table->string('error', 300)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamps();
            $table->unique(['email_campaign_id', 'email']);
            $table->index(['email_campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_recipients');
        Schema::dropIfExists('email_campaigns');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['marketing_source', 'marketing_updated_at']));
    }
};
