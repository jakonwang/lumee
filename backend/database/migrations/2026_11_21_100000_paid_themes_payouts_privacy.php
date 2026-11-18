<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paid marketplace themes: one-time price in USD cents (0 = free)
        Schema::table('theme_listings', function (Blueprint $t) {
            $t->unsignedInteger('price')->default(0);
        });
        Schema::create('theme_purchases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('theme_listing_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();      // who bought it
            $t->foreignId('platform_invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedInteger('amount');
            $t->unsignedInteger('developer_share');
            $t->foreignId('partner_payout_id')->nullable();
            $t->timestamps();
            $t->unique(['theme_listing_id', 'store_id']);   // bought once, reinstall free
        });

        // Developer payouts: monthly statements of earnings from paid invoices
        Schema::create('partner_payouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('period', 7);                       // 2026-11
            $t->unsignedInteger('amount');                 // USD cents
            $t->string('status', 12)->default('pending'); // pending | paid
            $t->string('method', 12)->nullable();          // paypal | bank (snapshot of the details at payout time)
            $t->text('details')->nullable();
            $t->string('reference', 120)->nullable();      // transfer id from ops
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'period']);
        });
        Schema::table('app_charge_bills', function (Blueprint $t) {
            $t->foreignId('partner_payout_id')->nullable();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->json('payout_details')->nullable();   // {method: paypal|bank, account: "..."} — encrypted cast
        });

        // Customer privacy requests (GDPR / CCPA): export and erasure
        Schema::create('privacy_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('email', 255);
            $t->string('type', 10);                       // export | erase
            $t->string('source', 12);                     // customer | merchant
            $t->string('status', 12)->default('pending'); // pending | completed | rejected
            $t->string('note', 300)->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_requests');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('payout_details'));
        Schema::table('app_charge_bills', fn (Blueprint $t) => $t->dropColumn('partner_payout_id'));
        Schema::dropIfExists('partner_payouts');
        Schema::dropIfExists('theme_purchases');
        Schema::table('theme_listings', fn (Blueprint $t) => $t->dropColumn('price'));
    }
};
