<?php

namespace Database\Seeders;

use App\Modules\Identity\Application\RoleProvisioner;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(RoleProvisioner::class)->syncPermissions();

        $this->call(PlanSeeder::class);

        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
