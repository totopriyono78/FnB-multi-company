<?php

namespace Database\Seeders;

use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Paket langganan awal. Harga masih placeholder bernilai 0 karena struktur harga
 * belum diputuskan (SRS §12.3 isu no. 6).
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'basic', 'name' => 'Basic', 'max_outlets' => 2, 'max_devices' => 4, 'max_users' => 15,
                'modules' => ['pos', 'menu', 'inventory', 'report']],
            ['code' => 'pro', 'name' => 'Pro', 'max_outlets' => 20, 'max_devices' => 60, 'max_users' => 200,
                'modules' => ['pos', 'menu', 'inventory', 'report', 'kds', 'crm', 'purchasing']],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'max_outlets' => null, 'max_devices' => null, 'max_users' => null,
                'modules' => ['pos', 'menu', 'inventory', 'report', 'kds', 'crm', 'purchasing', 'accounting', 'hr', 'franchise']],
        ];

        app(TenantContext::class)->runAsSystem(function () use ($plans): void {
            foreach ($plans as $plan) {
                Plan::query()->updateOrCreate(['code' => $plan['code']], $plan + ['price_per_outlet_month' => '0', 'is_active' => true]);
            }
        });
    }
}
