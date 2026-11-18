<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order lifecycle: cancel, (partial) refunds with restock, staff notes and an order timeline.
 * financial_status: pending | paid | partially_refunded | refunded | voided
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('refunded_total')->default(0)->after('total');
            $table->timestamp('cancelled_at')->nullable()->after('fulfilled_at');
            $table->string('cancel_reason', 40)->nullable()->after('cancelled_at');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('refunded_quantity')->default(0)->after('quantity');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount');                  // minor units
            $table->unsignedBigInteger('shipping_amount')->default(0);
            $table->string('reason', 255)->nullable();
            $table->boolean('restock')->default(false);
            $table->string('gateway', 32);
            $table->string('reference')->nullable();               // e.g. Stripe re_...
            $table->json('items')->nullable();                     // [{order_item_id, quantity}]
            $table->timestamps();
            $table->index(['store_id', 'created_at']);
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->string('message', 500);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['order_id', 'id']);
        });

        // Backfill a timeline for existing orders
        foreach (DB::table('orders')->orderBy('id')->cursor() as $o) {
            $rows = [['type' => 'placed', 'message' => '买家下单', 'created_at' => $o->created_at]];
            if ($o->paid_at) {
                $rows[] = ['type' => 'paid', 'message' => '订单已付款', 'created_at' => $o->paid_at];
            }
            if ($o->fulfilled_at) {
                $rows[] = ['type' => 'fulfilled', 'message' => '订单已发货', 'created_at' => $o->fulfilled_at];
            }
            DB::table('order_events')->insert(array_map(fn ($r) => $r + ['store_id' => $o->store_id, 'order_id' => $o->id], $rows));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('refunds');
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('refunded_quantity'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['refunded_total', 'cancelled_at', 'cancel_reason']));
    }
};
