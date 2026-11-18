<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Store API access tokens (private apps / integrations). Only a hash is stored.
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('token_prefix', 12);               // shown in the admin to tell tokens apart
            $table->string('token_hash', 64)->unique();       // sha256 of the full token
            $table->json('scopes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->json('topics');
            $table->string('secret', 64);                     // HMAC key shared with the receiver
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('failure_count')->default(0);   // consecutive failed deliveries
            $table->string('disabled_reason', 200)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_subscription_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id');
            $table->string('topic', 40);
            $table->json('payload');
            $table->string('status', 10)->default('pending');   // pending | success | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('response_body', 500)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['webhook_subscription_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
        Schema::dropIfExists('api_tokens');
    }
};
