<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full B2B: companies (buyers, locations, payment terms, tax exemption), quantity rules and
 * volume pricing on price lists, B2B-only products, quotes, and wholesale applications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('tax_id', 60)->nullable();
            $table->string('tag', 40)->default('wholesale');          // price lists for this tag apply to its buyers
            $table->unsignedSmallInteger('payment_terms')->nullable(); // Net N days (null = pay at checkout)
            $table->boolean('tax_exempt')->default(false);
            $table->json('locations')->nullable();                     // [{name, first_name, last_name, address1, address2, city, province, zip, country, phone}]
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'name']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('store_id')->constrained()->nullOnDelete();
            $table->string('company_role', 12)->nullable()->after('company_id');   // admin | buyer
            $table->boolean('tax_exempt')->default(false)->after('note');
        });

        Schema::table('price_list_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('price')->nullable()->change();   // rules without a fixed price use the list's %
            $table->unsignedInteger('min_qty')->default(1);
            $table->unsignedInteger('step')->default(1);                 // sold in multiples of
            $table->json('tiers')->nullable();                           // [{min: 10, price: 900}]
        });
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('b2b_only')->default(false)->after('is_gift_card');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('payment_due_at')->nullable()->after('paid_at');
            $table->timestamp('payment_reminded_at')->nullable()->after('payment_due_at');
            $table->unsignedBigInteger('company_id')->nullable()->after('customer_id');
            $table->string('po_number', 60)->nullable()->after('note');
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->uuid('token')->unique();
            $table->unsignedInteger('number');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('email');
            $table->string('status', 12)->default('requested');   // requested | sent | accepted | declined | expired
            $table->json('items');                                // [{variant_id, product_id, title, variant_title, sku, quantity, list_price, price}]
            $table->unsignedBigInteger('shipping_price')->nullable();
            $table->text('buyer_note')->nullable();
            $table->text('merchant_note')->nullable();
            $table->json('shipping_address')->nullable();
            $table->date('expires_on')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'status', 'id']);
        });

        Schema::create('b2b_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('company_name', 150);
            $table->string('tax_id', 60)->nullable();
            $table->string('contact_name', 100);
            $table->string('email');
            $table->string('phone', 40)->nullable();
            $table->string('website', 200)->nullable();
            $table->string('country', 2)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 12)->default('pending');     // pending | approved | rejected
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_applications');
        Schema::dropIfExists('quotes');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['payment_due_at', 'payment_reminded_at', 'company_id', 'po_number']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('b2b_only'));
        Schema::table('price_list_prices', fn (Blueprint $t) => $t->dropColumn(['min_qty', 'step', 'tiers']));
        Schema::table('customers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('company_id');
            $t->dropColumn(['company_role', 'tax_exempt']);
        });
        Schema::dropIfExists('companies');
    }
};
