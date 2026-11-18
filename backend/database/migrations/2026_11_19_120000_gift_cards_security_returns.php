<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Gift cards, admin two-factor sign-in + activity log, and customer return requests. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);                      // stored upper-case, no spaces
            $table->char('last4', 4);
            $table->unsignedBigInteger('initial_value');
            $table->unsignedBigInteger('balance');
            $table->char('currency', 3);
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_name', 100)->nullable();
            $table->string('message', 500)->nullable();
            $table->string('note', 500)->nullable();          // internal
            $table->date('expires_on')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'code']);
        });

        Schema::create('gift_card_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gift_card_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->bigInteger('amount');                     // + issued/restored, − spent
            $table->unsignedBigInteger('balance_after');
            $table->string('kind', 20);                       // issued | spent | restored | adjusted
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['gift_card_id', 'id']);
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->string('gift_card_code', 32)->nullable()->after('discount_code');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('gift_card_id')->nullable()->after('discount_total');
            $table->unsignedBigInteger('gift_card_total')->default(0)->after('gift_card_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 60);                    // e.g. products.update, orders.refund, auth.login
            $table->string('subject', 120)->nullable();      // e.g. "#1004", product title
            $table->string('method', 8)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['store_id', 'id']);
            $table->index(['user_id', 'id']);
        });

        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('requested');  // requested | approved | declined | completed
            $table->string('reason', 30);
            $table->text('customer_note')->nullable();
            $table->json('items');                                // [{order_item_id, quantity}]
            $table->text('merchant_note')->nullable();
            $table->string('return_instructions', 1000)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_requests');
        Schema::dropIfExists('activity_logs');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['gift_card_id', 'gift_card_total']));
        Schema::table('carts', fn (Blueprint $t) => $t->dropColumn('gift_card_code'));
        Schema::dropIfExists('gift_card_transactions');
        Schema::dropIfExists('gift_cards');
    }
};
