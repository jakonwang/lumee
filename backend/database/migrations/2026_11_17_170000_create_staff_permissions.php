<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Staff permissions per store membership (owners have everything implicitly)
        Schema::table('store_user', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
        });

        Schema::create('store_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->json('permissions');
            $table->string('token_hash', 64)->unique();   // sha256 of the emailed token; the token itself is never stored
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_invitations');
        Schema::table('store_user', fn (Blueprint $t) => $t->dropColumn('permissions'));
    }
};
