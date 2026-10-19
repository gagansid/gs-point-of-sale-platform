<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AppVersionSeeder::class);
        // Paket langganan bawaan (SaaS); paket yang sudah ada tidak diubah
        $this->call(PlanSeeder::class);
        $this->call(AddonSeeder::class);

        // Data demo tidak pernah masuk production. Super admin production: php artisan pos:create-admin
        if (app()->environment(['local', 'testing'])) {
            $this->call([DemoTenantSeeder::class, DemoProductImagesSeeder::class, DemoTransactionsSeeder::class]);
        }
    }
}
