<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('name');
            $table->string('handle', 63)->unique();          // used for {handle}.lumee.shop
            $table->char('currency', 3)->default('USD');
            $table->string('locale', 10)->default('en');
            $table->string('timezone', 64)->default('UTC');
            $table->string('theme', 64)->default('aurora');
            $table->string('status', 20)->default('active'); // active | paused | closed
            $table->string('plan', 32)->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('store_user', function (Blueprint $table) {
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('staff');    // owner | admin | staff
            $table->timestamps();
            $table->primary(['store_id', 'user_id']);
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('host')->unique();
            $table->string('type', 20)->default('custom');   // platform | custom
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
        Schema::dropIfExists('store_user');
        Schema::dropIfExists('stores');
    }
};
