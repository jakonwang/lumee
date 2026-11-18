<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Third-party apps (built by any Lumée account; listed in the app store after platform review)
        Schema::create('apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();   // the developer
            $table->string('name', 80);
            $table->string('slug', 90)->unique();
            $table->string('tagline', 160)->nullable();
            $table->text('description')->nullable();
            $table->string('icon_url', 500)->nullable();
            $table->string('app_url', 500);                    // opened from the merchant admin
            $table->json('redirect_uris');
            $table->json('scopes');
            $table->string('uninstall_url', 500)->nullable();  // signed POST when a store uninstalls
            $table->string('client_id', 40)->unique();
            $table->string('client_secret_hash', 64);
            $table->string('client_secret_prefix', 12);
            $table->string('status', 12)->default('draft');   // draft | review | listed | rejected
            $table->string('review_note', 300)->nullable();
            $table->timestamps();
        });

        Schema::create('app_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->json('scopes');
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'app_id']);
        });

        Schema::create('oauth_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->json('scopes');
            $table->string('redirect_uri', 500);
            $table->string('code_challenge', 128)->nullable();   // PKCE (S256)
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->foreignId('app_installation_id')->nullable()->after('store_id')->constrained()->cascadeOnDelete();
        });
        Schema::table('webhook_subscriptions', function (Blueprint $table) {
            $table->foreignId('app_installation_id')->nullable()->after('store_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_subscriptions', fn (Blueprint $t) => $t->dropConstrainedForeignId('app_installation_id'));
        Schema::table('api_tokens', fn (Blueprint $t) => $t->dropConstrainedForeignId('app_installation_id'));
        Schema::dropIfExists('oauth_codes');
        Schema::dropIfExists('app_installations');
        Schema::dropIfExists('apps');
    }
};
