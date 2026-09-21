<?php

// Ukur p50/p95 endpoint laporan & dashboard (Tahap 5) pada data demo.
// Jalankan: php artisan migrate:fresh --seed; php artisan serve --port=8123; php scripts/perf-reports.php
// Uji volume 50 outlet × 1 bulan (NFR-PERF-08): php scripts/perf-reports-volume.php (basis data terpisah).

use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = 'http://127.0.0.1:8123/api/v1';
$company = app(TenantContext::class)->runAsSystem(fn () => Company::query()->where('code', 'pt-kopi-nusantara-sejahtera')->firstOrFail());

function req(string $method, string $url, array $headers, ?array $body = null): array
{
    $ch = curl_init($url);
    $h = ['Accept: application/json', 'Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $h[] = "$k: $v";
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $t = microtime(true);
    $res = curl_exec($ch);
    $ms = (microtime(true) - $t) * 1000;

    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), $ms, $res];
}

[, , $login] = req('POST', "$base/auth/login", [], ['login' => 'rina@kopinusantara.test', 'password' => 'Rahasia123', 'device_name' => 'perf']);
$bo = ['Authorization' => 'Bearer '.json_decode((string) $login, true)['data']['token'], 'X-Company-Id' => $company->id];
$from = now('Asia/Jakarta')->subDays(14)->format('Y-m-d');
$to = now('Asia/Jakarta')->format('Y-m-d');
$period = "date_from={$from}&date_to={$to}";

// Laporan dibatasi 30 permintaan/menit per user: 20 sampel per laporan dengan jeda.
$cases = [
    'GET /dashboard' => [20, "$base/dashboard", false],
    'GET /reports/sales.day (15 hari)' => [20, "$base/reports/sales.day?$period", true],
    'GET /reports/sales.item' => [20, "$base/reports/sales.item?$period", true],
    'GET /reports/sales.payment' => [20, "$base/reports/sales.payment?$period", true],
    'GET /reports/tax' => [20, "$base/reports/tax?$period", true],
    'GET /reports/fraud' => [20, "$base/reports/fraud?$period", true],
    'GET /reports/gross_profit' => [20, "$base/reports/gross_profit?$period", true],
    'GET /reports/menu_engineering' => [20, "$base/reports/menu_engineering?$period", true],
    'GET /reports/inventory.movements' => [20, "$base/reports/inventory.movements?$period", true],
    'GET /reports/sales.item/export (xlsx)' => [10, "$base/reports/sales.item/export?format=xlsx&$period", true],
    'GET /reports/fraud/export (pdf)' => [10, "$base/reports/fraud/export?format=pdf&$period", true],
];
$reportCalls = 0;
foreach ($cases as $name => [$n, $url, $limited]) {
    $times = [];
    for ($i = 0; $i <= $n; $i++) {
        if ($limited && ++$reportCalls % 28 === 0) {
            sleep(61);
        }
        Cache::flush(); // dashboard: ukur tanpa cache 30 detik
        [$c, $ms, $body] = req('GET', $url, $bo);
        if ($c !== 200) {
            echo "$name -> HTTP $c ".substr((string) $body, 0, 200)."\n";
            break;
        }
        if ($i > 0) { // permintaan pertama = pemanasan
            $times[] = $ms;
        }
    }
    sort($times);
    $p = fn ($q) => $times === [] ? 0 : $times[(int) floor(($q / 100) * (count($times) - 1))];
    printf("%-40s n=%-3d p50=%7.1f ms  p95=%7.1f ms\n", $name, count($times), $p(50), $p(95));
}
