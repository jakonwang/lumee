<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('client_ip', 45)->nullable();
            $t->char('client_country', 2)->nullable();
            $t->unsignedTinyInteger('risk_score')->nullable();
            $t->string('risk_level', 10)->nullable();   // low | medium | high
            $t->json('risk_reasons')->nullable();
            $t->boolean('risk_hold')->default(false);     // fulfilment paused until the merchant reviews it
            $t->index(['store_id', 'client_ip', 'created_at']);
            $t->index(['store_id', 'risk_level']);
        });
        Schema::create('blocklist_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('type', 10);    // email | domain | ip
            $t->string('value', 255);
            $t->string('note', 255)->nullable();
            $t->unsignedInteger('hits')->default(0);
            $t->timestamps();
            $t->unique(['store_id', 'type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocklist_entries');
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['store_id', 'client_ip', 'created_at']);
            $t->dropIndex(['store_id', 'risk_level']);
            $t->dropColumn(['client_ip', 'client_country', 'risk_score', 'risk_level', 'risk_reasons', 'risk_hold']);
        });
    }
};
