<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('email', 255);
            $t->string('code', 40);                         // ?ref=CODE
            $t->decimal('commission_rate', 5, 2);           // percent of the order's merchandise total
            $t->string('token', 48)->unique();              // private stats page
            $t->string('status', 10)->default('active');   // active | paused
            $t->unsignedInteger('clicks')->default(0);
            $t->string('note', 300)->nullable();
            $t->timestamps();
            $t->unique(['store_id', 'code']);
        });
        Schema::create('affiliate_commissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('base_amount');
            $t->unsignedBigInteger('amount');
            $t->char('currency', 3);
            $t->decimal('rate', 5, 2);
            $t->string('status', 10)->default('pending');   // pending | paid | void
            $t->timestamp('paid_at')->nullable();
            $t->string('payout_reference', 120)->nullable();
            $t->timestamps();
            $t->index(['affiliate_id', 'status']);
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->unsignedBigInteger('affiliate_id')->nullable();
            $t->bigInteger('balance_due')->default(0);   // order editing: amount still to collect
            $t->unsignedInteger('edits_count')->default(0);
        });
        Schema::table('products', function (Blueprint $t) {
            $t->json('upsell_ids')->nullable();   // "Frequently bought together" (hand-picked; else from order history)
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('upsell_ids'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['affiliate_id', 'balance_due', 'edits_count']));
        Schema::dropIfExists('affiliate_commissions');
        Schema::dropIfExists('affiliates');
    }
};
