<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            // Abandoned checkout recovery: who reached checkout, in which language, and whether we emailed them
            $table->string('email')->nullable()->after('discount_code');
            $table->string('locale', 10)->nullable()->after('email');
            $table->timestamp('checkout_started_at')->nullable()->after('locale');
            $table->timestamp('recovery_sent_at')->nullable()->after('checkout_started_at');
            $table->index(['recovery_sent_at', 'checkout_started_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Placed from a cart we sent a recovery email for
            $table->boolean('recovered')->default(false)->after('dispute');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('recovered'));
        Schema::table('carts', function (Blueprint $t) {
            $t->dropIndex(['recovery_sent_at', 'checkout_started_at']);
            $t->dropColumn(['email', 'locale', 'checkout_started_at', 'recovery_sent_at']);
        });
    }
};
