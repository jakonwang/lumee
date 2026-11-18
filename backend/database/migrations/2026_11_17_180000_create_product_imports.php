<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('filename');
            $table->string('mode', 20)->default('create');     // create (skip existing handles) | overwrite
            $table->string('status', 20)->default('preview');  // preview | queued | running | done | failed
            $table->longText('payload');                        // parsed products (JSON), consumed by the job
            $table->json('summary')->nullable();                // counts shown in the preview
            $table->json('results')->nullable();                // created/updated/skipped/failed + errors
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_imports');
    }
};
