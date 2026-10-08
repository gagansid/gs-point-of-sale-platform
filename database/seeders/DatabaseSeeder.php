<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AppVersionSeeder::class);

        // Data demo tidak pernah masuk production. Super admin production: php artisan pos:create-admin
        if (app()->environment(['local', 'testing'])) {
            $this->call([DemoTenantSeeder::class, DemoTransactionsSeeder::class]);
        }
    }
}
