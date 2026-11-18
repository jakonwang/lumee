<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use App\Services\StoreProvisioner;
use App\Tenancy\CurrentStore;
use Illuminate\Database\Seeder;

/**
 * Local demo data: php artisan db:seed --class=DemoSeeder
 * Login: demo@lumee.test / demo-pass-123   Storefront: http://aurora-jewels.lumee.localhost:8000
 */
class DemoSeeder extends Seeder
{
    public function run(StoreProvisioner $provisioner, CurrentStore $current): void
    {
        if (User::where('email', 'demo@lumee.test')->exists()) {
            $this->command?->warn('Demo merchant already exists — skipping.');

            return;
        }

        $user = User::create(['name' => 'Demo', 'email' => 'demo@lumee.test', 'password' => 'demo-pass-123']);
        $store = $provisioner->create($user, 'Aurora Jewels');
        $current->set($store);

        $products = [
            ['Amethyst Butterfly Necklace', 'A delicate butterfly pendant set with lavender amethyst on an 18K rose-gold vermeil chain.', 12900, 15900, 12],
            ['Rose Quartz Stud Earrings', 'Petite rose quartz studs in a soft blush hue. Hypoallergenic sterling silver posts.', 4900, null, 30],
            ['Aquamarine Teardrop Ring', 'A teardrop aquamarine framed in polished rose gold — the color of a calm sea.', 8900, null, 8],
            ['Citrine Charm Bracelet', 'Sunny citrine charms on an adjustable chain. Layers beautifully.', 6900, 7900, 0],
        ];

        foreach ($products as [$title, $description, $price, $compareAt, $stock]) {
            $product = Product::create([
                'title' => $title,
                'handle' => \Illuminate\Support\Str::slug($title),
                'description' => $description,
                'status' => 'active',
                'product_type' => 'Jewelry',
            ]);
            $product->variants()->create([
                'price' => $price,
                'compare_at_price' => $compareAt,
                'inventory_quantity' => $stock,
            ]);
        }

        $this->command?->info("Demo store ready: {$store->storefrontUrl()}  (login demo@lumee.test)");
    }
}
