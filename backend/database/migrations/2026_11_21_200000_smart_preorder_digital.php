<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Smart collections: products are added automatically when they match the rules
        Schema::table('collections', function (Blueprint $t) {
            $t->json('rules')->nullable();             // [{field, op, value}] — null = manual collection
            $t->string('rules_match', 3)->default('all');   // all | any
        });

        Schema::table('products', function (Blueprint $t) {
            $t->json('preorder')->nullable();          // {enabled, ships_at, note, limit}
            $t->boolean('digital')->default(false);    // delivered as file downloads, no shipping
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->boolean('preorder')->default(false);
        });

        Schema::create('product_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('path', 255);                   // private disk
            $t->string('filename', 200);
            $t->unsignedBigInteger('size');
            $t->timestamps();
        });
        Schema::create('order_downloads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_file_id')->constrained()->cascadeOnDelete();
            $t->string('token', 48)->unique();
            $t->unsignedSmallInteger('downloads')->default(0);
            $t->unsignedSmallInteger('max_downloads')->default(5);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('last_downloaded_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_downloads');
        Schema::dropIfExists('product_files');
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('preorder'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['preorder', 'digital']));
        Schema::table('collections', fn (Blueprint $t) => $t->dropColumn(['rules', 'rules_match']));
    }
};
