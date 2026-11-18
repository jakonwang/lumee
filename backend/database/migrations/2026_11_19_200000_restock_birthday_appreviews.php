<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Back-in-stock alerts, birthday + browse-abandonment flows (customer data), app store reviews. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id');
            $table->string('email');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('locale', 10)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['variant_id', 'email']);
            $table->index(['store_id', 'variant_id', 'notified_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->date('birthday')->nullable()->after('phone');
            $table->unsignedBigInteger('last_viewed_product_id')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->index(['store_id', 'last_viewed_at']);
        });

        Schema::create('app_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();
            $table->text('developer_reply')->nullable();
            $table->timestamps();
            $table->unique(['app_id', 'store_id']);
        });
        Schema::table('apps', function (Blueprint $table) {
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('apps', fn (Blueprint $t) => $t->dropColumn(['rating_avg', 'rating_count']));
        Schema::dropIfExists('app_reviews');
        Schema::table('customers', function (Blueprint $t) {
            $t->dropIndex(['store_id', 'last_viewed_at']);
            $t->dropColumn(['birthday', 'last_viewed_product_id', 'last_viewed_at']);
        });
        Schema::dropIfExists('stock_alerts');
    }
};
