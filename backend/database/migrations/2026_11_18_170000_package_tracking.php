<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Package tracking via 17TRACK: latest status, carrier it detected, and the event timeline
            $table->string('tracking_status', 24)->nullable()->after('tracking_number');
            $table->string('tracking_carrier', 100)->nullable()->after('tracking_status');
            $table->json('tracking_events')->nullable()->after('tracking_carrier');
            $table->timestamp('tracking_registered_at')->nullable()->after('tracking_events');
            $table->timestamp('tracking_synced_at')->nullable()->after('tracking_registered_at');
            $table->timestamp('delivered_at')->nullable()->after('fulfilled_at');
            $table->index(['fulfillment_status', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['fulfillment_status', 'delivered_at']);
            $t->dropColumn(['tracking_status', 'tracking_carrier', 'tracking_events', 'tracking_registered_at', 'tracking_synced_at', 'delivered_at']);
        });
    }
};
