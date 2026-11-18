<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Gift cards sold as products, loyalty points, and an SMS log. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_gift_card')->default(false)->after('taxable');
        });
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->unsignedBigInteger('order_item_id')->nullable()->after('customer_id');   // bought in the store
            $table->string('source', 20)->default('admin')->after('order_item_id');            // admin | purchase | points
            $table->index(['store_id', 'order_item_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->integer('points')->default(0)->after('total_spent');
        });
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->integer('points');                       // + earned / adjusted, − redeemed / reversed
            $table->integer('balance_after');
            $table->string('kind', 20);                      // earned | redeemed | reversed | adjusted
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('gift_card_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['customer_id', 'id']);
            $table->index(['store_id', 'order_id']);
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('type', 30);                      // order_confirmation | shipping_confirmation | test
            $table->string('to', 30);
            $table->string('body', 640);
            $table->string('status', 12)->default('queued'); // queued | sent | failed
            $table->string('provider_id', 64)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['store_id', 'id']);
            $table->unique(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('point_transactions');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('points'));
        Schema::table('gift_cards', function (Blueprint $t) {
            $t->dropIndex(['store_id', 'order_item_id']);
            $t->dropColumn(['order_item_id', 'source']);
        });
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('is_gift_card'));
    }
};
