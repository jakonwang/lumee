<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Price history per market too: a market's fixed price for a variant (market currency). A row with no price means
 * the fixed price was removed and the variant follows the store price there again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variant_price_history', function (Blueprint $table) {
            $table->foreignId('market_id')->nullable()->after('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('price')->nullable()->change();
        });

        DB::table('market_prices')->whereNotNull('price')->orderBy('id')->chunk(1000, function ($rows) {
            DB::table('variant_price_history')->insert($rows->map(fn ($p) => [
                'store_id' => $p->store_id, 'product_variant_id' => $p->variant_id, 'market_id' => $p->market_id,
                'price' => (int) $p->price, 'created_at' => $p->updated_at ?? now(),
            ])->all());
        });
    }

    public function down(): void
    {
        DB::table('variant_price_history')->whereNotNull('market_id')->delete();
        Schema::table('variant_price_history', function (Blueprint $table) {
            $table->dropConstrainedForeignId('market_id');
        });
    }
};
