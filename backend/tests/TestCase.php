<?php

namespace Tests;

use App\Modules\Identity\Application\RoleProvisioner;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Notification;

abstract class TestCase extends BaseTestCase
{
    protected bool $seedReferenceData = true;

    /**
     * Setiap request uji memakai guard baru agar token berbeda dalam satu test tidak tercampur.
     *
     * @param  string  $method
     * @param  string  $uri
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     * @param  string|null  $content
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->guard('sanctum')->forgetUser();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * Test menghitung "hari ini" dari jam dinding. Di sekitar tengah malam WIB, shift yang dibuka "1 jam lalu" jatuh
     * ke hari bisnis sebelumnya sehingga hasil berbeda-beda. Pada 21.00–03.00 WIB jam test dipindah ke 12.00 WIB
     * (hari bisnis yang sedang berjalan) agar hasil test tidak bergantung pada jam dijalankan.
     */
    private function avoidMidnight(): void
    {
        // Dua jendela berbahaya: melewati tengah malam WIB (21.00-24.00) dan
        // saat tanggal UTC masih berbeda dengan tanggal WIB (00.00-07.00).
        $local = CarbonImmutable::now('Asia/Jakarta');
        if ($local->hour >= 7 && $local->hour < 21) {
            return;
        }
        $day = $local->hour < 7 ? $local->subDay() : $local;
        $this->travelTo($day->setTime(12, 0));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->avoidMidnight();

        if ($this->seedReferenceData && in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            Notification::fake();
            app(RoleProvisioner::class)->syncPermissions();
            $this->seed(PlanSeeder::class);
        }
    }
}
