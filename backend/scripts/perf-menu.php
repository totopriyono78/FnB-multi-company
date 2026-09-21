<?php

// Ukur p50/p95 endpoint menu. Jalankan: php artisan migrate:fresh --seed; php artisan serve --port=8123; php scripts/perf-menu.php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Tenancy\Application\DevicePairingService;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Contracts\Console\Kernel;

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
[$code, , $login] = req('POST', "$base/auth/login", [], ['login' => 'rina@kopinusantara.test', 'password' => 'Rahasia123', 'device_name' => 'perf']);
$token = $login['data']['token'] ?? null;
if (! $token) {
    var_dump($code, $login);
    exit(1);
}
$bo = ['Authorization' => "Bearer $token", 'X-Company-Id' => $company->id];

[$kemang, $device, $items] = $ctx->runAsTenant($company->id, function () {
    $o = Outlet::query()->where('code', 'KMG')->firstOrFail();
    $d = Device::query()->where('outlet_id', $o->id)->where('code', 'POS01')->firstOrFail();
    $i = Item::query()->with('variants')->where('brand_id', $o->brand_id)->whereIn('sku', ['CRS-01', 'KSTJ-01'])->get()->keyBy('sku');

    return [$o, $d, $i];
});
$code = $ctx->runAsTenant($company->id, fn () => app(DevicePairingService::class)->issueCode(Device::query()->find($device->id))['code']);
[, , $pair] = req('POST', "$base/devices/pair", [], ['code' => $code, 'platform' => 'android', 'app_version' => '1.0.0']);
$dev = ['Authorization' => 'Bearer '.$pair['data']['token']];

$ks = $items['KSTJ-01'];
$mods = $ctx->runAsTenant($company->id, fn () => $ks->modifierGroups()->with('modifiers')->get()->map(fn ($g) => $g->modifiers->first()->id)->all());
$cart = ['channel_code' => 'dine_in', 'lines' => [
    ['item_id' => $ks->id, 'variant_id' => $ks->variants->firstWhere('name', 'Large')->id, 'qty' => 2, 'modifiers' => array_map(fn ($id) => ['id' => $id], $mods)],
    ['item_id' => $items['CRS-01']->id, 'qty' => 1],
]];

$cases = [
    'GET /items' => fn () => req('GET', "$base/items?per_page=50", $bo),
    'GET /items/{id}' => fn () => req('GET', "$base/items/{$ks->id}", $bo),
    'GET /promotions' => fn () => req('GET', "$base/promotions", $bo),
    'POST /quotes' => fn () => req('POST', "$base/quotes", $bo, $cart + ['outlet_id' => $kemang->id]),
    'GET /pos/catalog' => fn () => req('GET', "$base/pos/catalog", $dev),
    'POST /pos/quotes' => fn () => req('POST', "$base/pos/quotes", $dev, $cart),
];
foreach ($cases as $name => $fn) {
    $fn(); // pemanasan
    $times = [];
    for ($i = 0; $i < 40; $i++) {
        [$c, $ms] = $fn();
        if ($c !== 200) {
            echo "$name -> HTTP $c\n";
            break;
        } $times[] = $ms;
    }
    sort($times);
    printf("%-18s p50 %6.1f ms  p95 %6.1f ms  (n=%d)\n", $name, $times[(int) (count($times) * 0.5)], $times[(int) ceil(count($times) * 0.95) - 1], count($times));
}
