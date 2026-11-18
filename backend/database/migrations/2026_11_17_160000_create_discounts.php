<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);                         // stored upper-case
            $table->string('type', 20);                         // percentage | fixed | free_shipping
            $table->unsignedBigInteger('value')->default(0);    // percentage: 1–100; fixed: minor units
            $table->unsignedBigInteger('min_subtotal')->nullable();
            $table->unsignedInteger('usage_limit')->nullable(); // total uses across all customers
            $table->unsignedInteger('usage_count')->default(0);
            $table->boolean('once_per_customer')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'code']);
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->string('discount_code', 40)->nullable()->after('token');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('discount_code', 40)->nullable()->after('shipping_total');
            $table->unsignedBigInteger('discount_total')->default(0)->after('discount_code');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['discount_code', 'discount_total']));
        Schema::table('carts', fn (Blueprint $t) => $t->dropColumn('discount_code'));
        Schema::dropIfExists('discounts');
    }
};
