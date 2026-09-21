<?php

// Ukur p50/p95 endpoint inventory & pembelian (Tahap 4). Potong stok saat sinkronisasi diukur oleh perf-sales.php.
// Jalankan: php artisan migrate:fresh --seed; php artisan serve --port=8123; php scripts/perf-inventory.php

use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\StockCount;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Purchasing\Domain\Models\PurchaseOrder;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = 'http://127.0.0.1:8123/api/v1';
$ctx = app(TenantContext::class);
$company = $ctx->runAsSystem(fn () => Company::query()->where('code', 'pt-kopi-nusantara-sejahtera')->firstOrFail());

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

    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), $ms, json_decode((string) $res, true)];
}

[, , $login] = req('POST', "$base/auth/login", [], ['login' => 'rina@kopinusantara.test', 'password' => 'Rahasia123', 'device_name' => 'perf']);
$bo = ['Authorization' => 'Bearer '.$login['data']['token'], 'X-Company-Id' => $company->id];

[$outlet, $location, $item, $milk, $po, $count] = $ctx->runAsTenant($company->id, function () {
    $o = Outlet::query()->where('code', 'KMG')->firstOrFail();

    return [
        $o,
        StockLocation::query()->where('outlet_id', $o->id)->where('is_default', true)->firstOrFail(),
        Item::query()->where('sku', 'KSTJ-01')->firstOrFail(),
        Ingredient::query()->where('code', 'SUSU-SEGAR')->firstOrFail(),
        PurchaseOrder::query()->where('status', 'partially_received')->firstOrFail(),
        StockCount::query()->firstOrFail(),
    ];
});

$cases = [
    'GET /ingredients (50)' => [40, fn () => req('GET', "$base/ingredients?per_page=50", $bo)],
    'GET /stock/balances (50)' => [40, fn () => req('GET', "$base/stock/balances?per_page=50&outlet_id={$outlet->id}", $bo)],
    'GET /stock/movements (50)' => [40, fn () => req('GET', "$base/stock/movements?per_page=50&location_id={$location->id}", $bo)],
    'GET /recipes/item/{id} + HPP' => [40, fn () => req('GET', "$base/recipes/item/{$item->id}?outlet_id={$outlet->id}", $bo)],
    'GET /reports/food-cost/menu' => [40, fn () => req('GET', "$base/reports/food-cost/menu?outlet_id={$outlet->id}", $bo)],
    'GET /reports/food-cost' => [40, fn () => req('GET', "$base/reports/food-cost", $bo)],
    'GET /purchase-orders/{id}' => [40, fn () => req('GET', "$base/purchase-orders/{$po->id}", $bo)],
    'GET /stock-counts/{id}' => [40, fn () => req('GET', "$base/stock-counts/{$count->id}", $bo)],
    'POST /stock-adjustments (waste)' => [30, fn () => req('POST', "$base/stock-adjustments", $bo, [
        'location_id' => $location->id, 'type' => 'waste', 'reason_code' => 'spilled',
        'lines' => [['ingredient_id' => $milk->id, 'qty' => '1']],
    ])],
];
$sent = 1;
foreach ($cases as $name => [$n, $fn]) {
    // Pembatas laju API 300/menit per user (sesuai rancangan): jeda sebelum kuota menit berjalan habis.
    if ($sent + $n + 1 > 280) {
        sleep(61);
        $sent = 0;
    }
    $sent += $n + 1;
    $fn(); // pemanasan
    $times = [];
    for ($i = 0; $i < $n; $i++) {
        [$c, $ms, $body] = $fn();
        if (! in_array($c, [200, 201], true)) {
            echo "$name -> HTTP $c ".json_encode($body['errors'] ?? null)."\n";
            break;
        }
        $times[] = $ms;
    }
    sort($times);
    $p = fn ($q) => $times === [] ? 0 : $times[(int) floor(($q / 100) * (count($times) - 1))];
    printf("%-34s n=%-3d p50=%6.1f ms  p95=%6.1f ms\n", $name, count($times), $p(50), $p(95));
}
