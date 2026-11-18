<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Theme marketplace: developers submit a Lumée theme package; ops review; merchants install
        Schema::create('theme_listings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();   // the developer
            $t->string('name', 80);
            $t->string('slug', 90)->unique();
            $t->string('tagline', 160)->nullable();
            $t->text('description')->nullable();
            $t->string('preview_url', 500)->nullable();   // screenshot (https)
            $t->string('demo_url', 500)->nullable();
            $t->string('theme', 40);                       // base template the package is built on
            $t->string('package_path', 255)->nullable();   // private disk
            $t->unsignedInteger('version')->default(0);
            $t->json('swatches')->nullable();
            $t->string('status', 12)->default('draft');   // draft | review | listed | rejected
            $t->string('review_note', 300)->nullable();
            $t->unsignedInteger('installs')->default(0);
            $t->timestamps();
        });
        Schema::table('store_themes', function (Blueprint $t) {
            $t->foreignId('theme_listing_id')->nullable()->constrained()->nullOnDelete();
        });

        // App billing: an app asks for a charge, the merchant approves it in the admin, the platform bills it
        Schema::create('app_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('app_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('app_installation_id')->nullable()->constrained()->nullOnDelete();
            $t->string('token', 40)->unique();            // confirmation link
            $t->string('name', 100);
            $t->unsignedInteger('price');                  // USD cents
            $t->string('interval', 16);                    // every_30_days | one_time
            $t->unsignedSmallInteger('trial_days')->default(0);
            $t->string('return_url', 500);
            $t->boolean('test')->default(false);
            $t->string('status', 12)->default('pending'); // pending | active | declined | cancelled | expired
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamp('billing_on')->nullable();        // next time it's billed
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['store_id', 'status']);
            $t->index(['status', 'billing_on']);
        });
        // Each billed period: what the store paid and the developer's share (80%)
        Schema::create('app_charge_bills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('app_charge_id')->constrained()->cascadeOnDelete();
            $t->foreignId('app_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('platform_invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedInteger('amount');
            $t->unsignedInteger('developer_share');
            $t->timestamp('period_start');
            $t->timestamp('period_end')->nullable();
            $t->timestamps();
        });

        // Embedded apps open inside the admin (App Bridge)
        Schema::table('apps', function (Blueprint $t) {
            $t->boolean('embedded')->default(false);
            $t->string('pricing', 80)->nullable();   // shown in the app store, e.g. "Free · Pro $9/month"
        });
    }

    public function down(): void
    {
        Schema::table('apps', fn (Blueprint $t) => $t->dropColumn(['embedded', 'pricing']));
        Schema::dropIfExists('app_charge_bills');
        Schema::dropIfExists('app_charges');
        Schema::table('store_themes', fn (Blueprint $t) => $t->dropConstrainedForeignId('theme_listing_id'));
        Schema::dropIfExists('theme_listings');
    }
};
