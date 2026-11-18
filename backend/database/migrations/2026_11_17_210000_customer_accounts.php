<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront customer accounts. A customer row exists per store + email (created by guest
 * checkout too); setting a password turns it into an account. Orders are only shown once the
 * email is verified, so registering someone else's address never reveals their orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('password')->nullable()->after('phone');
            $table->timestamp('email_verified_at')->nullable()->after('password');
            $table->boolean('accepts_marketing')->default(false)->after('email_verified_at');
            $table->json('default_address')->nullable()->after('accepts_marketing');
            $table->timestamp('last_login_at')->nullable()->after('default_address');
        });

        Schema::create('customer_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);                 // verify | reset
            $table->char('token_hash', 64)->unique();   // sha256 of the emailed token
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['customer_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_tokens');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['password', 'email_verified_at', 'accepts_marketing', 'default_address', 'last_login_at']));
    }
};
