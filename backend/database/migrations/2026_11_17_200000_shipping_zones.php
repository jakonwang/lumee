<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shipping zones (Shopify style): a zone is a set of countries with its own rates.
 * The zone without countries is "rest of world" and serves every country no other zone lists.
 * Rates can be limited by order amount and/or cart weight (variants get a weight in grams).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('countries')->nullable();          // null/empty = rest of world
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('shipping_rates', function (Blueprint $table) {
            $table->foreignId('zone_id')->nullable()->after('store_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->string('description', 150)->nullable()->after('name');    // e.g. "7–12 business days"
            $table->unsignedBigInteger('max_subtotal')->nullable()->after('min_subtotal');
            $table->unsignedInteger('min_weight')->nullable()->after('max_subtotal');   // grams
            $table->unsignedInteger('max_weight')->nullable()->after('min_weight');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('weight')->default(0)->after('inventory_quantity');   // grams
        });

        // Move existing rates into zones: one "rest of world" zone per store, plus one zone per country list
        $now = now();
        foreach (DB::table('stores')->pluck('id') as $storeId) {
            $zones = [];
            foreach (DB::table('shipping_rates')->where('store_id', $storeId)->orderBy('position')->get() as $rate) {
                $countries = json_decode((string) $rate->countries, true) ?: [];
                sort($countries);
                $key = implode(',', $countries);
                if (! isset($zones[$key])) {
                    $zones[$key] = DB::table('shipping_zones')->insertGetId([
                        'store_id' => $storeId,
                        'name' => $countries ? implode(', ', array_slice($countries, 0, 5)) : '全球',
                        'countries' => $countries ? json_encode($countries) : null,
                        'position' => $countries ? count($zones) : 999,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                DB::table('shipping_rates')->where('id', $rate->id)->update(['zone_id' => $zones[$key]]);
            }
        }

        Schema::table('shipping_rates', function (Blueprint $table) {
            $table->dropColumn('countries');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_rates', function (Blueprint $table) {
            $table->json('countries')->nullable();
        });
        foreach (DB::table('shipping_rates')->get() as $rate) {
            $countries = DB::table('shipping_zones')->where('id', $rate->zone_id)->value('countries');
            DB::table('shipping_rates')->where('id', $rate->id)->update(['countries' => $countries]);
        }
        Schema::table('shipping_rates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zone_id');
            $table->dropColumn(['description', 'max_subtotal', 'min_weight', 'max_weight']);
        });
        Schema::table('product_variants', fn (Blueprint $t) => $t->dropColumn('weight'));
        Schema::dropIfExists('shipping_zones');
    }
};
