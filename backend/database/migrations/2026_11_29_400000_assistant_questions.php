<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What shoppers ask the AI shopping assistant (shown to the merchant: questions, answers, products suggested). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('visitor', 64)->nullable();
            $table->string('locale', 10)->nullable();
            $table->string('question', 500);
            $table->text('reply');
            $table->json('product_ids')->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_questions');
    }
};
