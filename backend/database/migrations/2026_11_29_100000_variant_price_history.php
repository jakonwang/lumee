<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Each variant's price changes, for the EU rule "show the lowest price of the 30 days before a discount". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variant_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('price');   // minor units, store currency
            $table->timestamp('created_at')->index();
            $table->index(['product_variant_id', 'created_at']);
        });

        // Start from today's prices (nothing older is known)
        DB::table('product_variants')->orderBy('id')->select('id', 'store_id', 'price', 'created_at')->chunk(1000, function ($rows) {
            DB::table('variant_price_history')->insert($rows->map(fn ($v) => [
                'store_id' => $v->store_id, 'product_variant_id' => $v->id, 'price' => max(0, (int) $v->price), 'created_at' => $v->created_at ?? now(),
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_price_history');
    }
};
