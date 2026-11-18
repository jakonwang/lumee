<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per storefront visit (30 minutes of inactivity starts a new one). No IP or personal data.
        Schema::create('analytics_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->char('token', 36)->unique();          // session cookie
            $table->char('visitor', 36);                 // long-lived visitor cookie (unique visitors, returning)
            $table->boolean('returning')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('pageviews')->default(0);
            $table->string('landing_path', 255)->default('/');
            $table->string('referrer_host', 120)->nullable();
            $table->string('source', 16)->default('direct');   // direct | search | social | email | paid | referral
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('device', 8)->default('desktop');   // mobile | tablet | desktop
            $table->string('browser', 20)->nullable();
            $table->boolean('viewed_product')->default(false);
            $table->boolean('added_to_cart')->default(false);
            $table->boolean('reached_checkout')->default(false);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['store_id', 'started_at']);
            $table->index(['store_id', 'last_seen_at']);
        });

        // Per product per day (store time zone): views and add-to-carts
        Schema::create('analytics_product_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('carts')->default(0);
            $table->unique(['store_id', 'product_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_product_stats');
        Schema::dropIfExists('analytics_sessions');
    }
};
