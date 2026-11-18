<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Wholesale price lists (by customer tag) and subscribe-and-save subscriptions. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('price_lists')) {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('customer_tag', 40);              // customers with this tag get the list
            $table->decimal('percent_off', 5, 2)->default(0); // across the catalog
            $table->unsignedBigInteger('min_subtotal')->default(0);   // wholesale minimum order (minor units)
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['store_id', 'customer_tag']);
        });
        }
        if (! Schema::hasTable('price_list_prices')) {
        Schema::create('price_list_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('variant_id');
            $table->unsignedBigInteger('price');              // fixed price, overrides the percentage
            $table->unique(['price_list_id', 'variant_id']);
        });
        }

        if (! Schema::hasColumn('products', 'subscription')) {
            Schema::table('products', function (Blueprint $table) {
                // {"enabled":true,"discount":10,"intervals":["week:2","month:1"],"only":false}
                $table->json('subscription')->nullable()->after('is_gift_card');
            });
        }
        if (! Schema::hasColumn('cart_items', 'plan')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->string('plan', 20)->nullable()->after('quantity');   // "month:1" = every month
            });
        }
        // Same variant can be in the bag once as one-time and once as a subscription.
        // The FK on cart_id needs its own index before the unique (cart_id, variant_id) can go.
        Schema::table('cart_items', function (Blueprint $table) {
            $table->index('cart_id', 'cart_items_cart_id_index');
        });
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique(['cart_id', 'variant_id']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('plan', 20)->nullable()->after('quantity');
        });

        Schema::create('customer_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->uuid('token')->unique();                   // buyer's manage link
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('status', 12)->default('active');   // active | paused | past_due | cancelled
            $table->string('interval', 20);                    // "month:1"
            $table->decimal('discount', 5, 2)->default(0);
            $table->json('items');                             // [{variant_id, product_id, quantity, title, variant_title}]
            $table->json('shipping_address');
            $table->json('shipping_method')->nullable();
            $table->char('currency', 3);
            $table->string('payment_gateway', 20);
            $table->string('stripe_customer', 64)->nullable();
            $table->string('stripe_payment_method', 64)->nullable();
            $table->string('locale', 10)->nullable();
            $table->timestamp('next_billing_at')->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->string('last_error', 300)->nullable();
            $table->unsignedBigInteger('origin_order_id')->nullable();
            $table->unsignedBigInteger('last_order_id')->nullable();
            $table->unsignedInteger('orders_count')->default(1);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_billing_at']);
            $table->index(['store_id', 'status', 'id']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_id')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('subscription_id'));
        Schema::dropIfExists('customer_subscriptions');
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('plan'));
        Schema::table('cart_items', function (Blueprint $t) {
            $t->dropColumn('plan');
            $t->unique(['cart_id', 'variant_id']);
            $t->dropIndex('cart_items_cart_id_index');
        });
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('subscription'));
        Schema::dropIfExists('price_list_prices');
        Schema::dropIfExists('price_lists');
    }
};
