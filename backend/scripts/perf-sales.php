<?php

// Ukur p50/p95 endpoint transaksi & sinkronisasi (Tahap 3).
// Jalankan: php artisan migrate:fresh --seed; php artisan serve --port=8123; php scripts/perf-sales.php

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\DevicePairingService;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

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

[$outlet, $device] = $ctx->runAsTenant($company->id, function () {
    $o = Outlet::query()->where('code', 'KMG')->firstOrFail();

    return [$o, Device::query()->where('outlet_id', $o->id)->where('code', 'POS02')->firstOrFail()];
});
$cashierId = $ctx->runAsSystem(fn () => User::query()->where('email', 'siti@kopinusantara.test')->value('id'));
$code = $ctx->runAsTenant($company->id, fn () => app(DevicePairingService::class)->issueCode(Device::query()->find($device->id))['code']);
[, , $pair] = req('POST', "$base/devices/pair", [], ['code' => $code, 'platform' => 'android', 'app_version' => '1.0.0']);
$dev = ['Authorization' => 'Bearer '.$pair['data']['token']];

[, , $catalog] = req('GET', "$base/sync/pull", $dev);
$items = collect($catalog['data']['snapshot']['catalog']['items'])->keyBy('sku');
$ks = $items['KSTJ-01'];
$modGroups = collect($catalog['data']['snapshot']['catalog']['modifier_groups'])->keyBy('id');
$mods = collect($ks['modifier_group_ids'] ?? [])->map(fn ($g) => $modGroups[$g]['min_select'] > 0 ? ['id' => $modGroups[$g]['modifiers'][0]['id']] : null)->filter()->values()->all();
$cart = ['channel_code' => 'take_away', 'lines' => [
    ['item_id' => $ks['id'], 'variant_id' => collect($ks['variants'])->firstWhere('name', 'Large')['id'], 'qty' => 2, 'modifiers' => $mods],
    ['item_id' => $items['CRS-01']['id'], 'qty' => 1],
    ['item_id' => $items['AMR-01']['id'], 'variant_id' => collect($items['AMR-01']['variants'])->firstWhere('name', 'Regular')['id'], 'qty' => 1, 'modifiers' => collect($items['AMR-01']['modifier_group_ids'] ?? [])->map(fn ($g) => $modGroups[$g]['min_select'] > 0 ? ['id' => $modGroups[$g]['modifiers'][0]['id']] : null)->filter()->values()->all()],
]];
[$qc, , $quote] = req('POST', "$base/pos/quotes", $dev, $cart);
if ($qc !== 200) {
    var_dump($quote);
    exit(1);
}
$q = $quote['data'];

$shiftId = (string) Str::uuid7();
[, , $opened] = req('POST', "$base/sync/push", $dev, ['batch_id' => (string) Str::uuid7(), 'entities' => [[
    'type' => 'shift.open', 'id' => $shiftId, 'payload' => ['cashier_id' => $cashierId, 'opening_cash' => '0', 'opened_at' => now()->subMinutes(30)->toIso8601String()],
]]]);
if (($opened['data']['results'][0]['status'] ?? null) !== 'accepted') {
    var_dump($opened);
    exit(1);
}
$ymd = now()->timezone($outlet->timezone)->format('ymd');
$seq = 0;

$order = function () use (&$seq, $q, $shiftId, $cashierId, $outlet, $device, $ymd): array {
    $seq++;
    $id = (string) Str::uuid7();

    return ['type' => 'order', 'id' => $id, 'payload' => [
        'shift_id' => $shiftId, 'cashier_id' => $cashierId,
        'receipt_no' => sprintf('%s-%s-%s-%05d', $outlet->code, $device->code, $ymd, $seq),
        'channel_code' => 'take_away', 'status' => 'paid',
        'created_at' => now()->subMinute()->toIso8601String(),
        'pricing' => [
            'tax_name' => $q['tax']['name'], 'tax_rate' => $q['tax']['rate'], 'tax_inclusive' => $q['tax']['inclusive'],
            'tax_on_service_charge' => $outlet->tax_on_service_charge, 'service_charge_rate' => (string) $outlet->service_charge_rate,
            'service_charge_applies' => false, 'rounding_unit' => $outlet->rounding_unit, 'rounding_mode' => $outlet->rounding_mode,
        ],
        'lines' => array_map(fn ($l) => [
            'id' => (string) Str::uuid7(), 'item_id' => $l['item_id'], 'variant_id' => $l['variant']['id'] ?? null, 'name' => $l['name'],
            'qty' => $l['qty'], 'unit_price' => $l['unit_price'],
            'modifiers' => array_map(fn ($m) => ['id' => $m['id'], 'name' => $m['name'], 'price' => $m['price'], 'qty' => $m['qty']], $l['modifiers']),
            'discounts' => array_map(fn ($d) => ['type' => $d['type'], 'value' => $d['value'], 'source' => $d['source']], $l['discounts']),
        ], $q['lines']),
        'totals' => array_intersect_key($q['totals'], array_flip(['subtotal', 'item_discount', 'order_discount', 'service_charge', 'tax', 'rounding', 'total'])),
        'payments' => [['id' => (string) Str::uuid7(), 'method' => 'cash', 'amount' => $q['totals']['total'], 'created_at' => now()->toIso8601String()]],
    ]];
};

$version = $catalog['data']['version'];
$cases = [
    'POST /sync/push (1 transaksi)' => [40, fn () => req('POST', "$base/sync/push", $dev, ['batch_id' => (string) Str::uuid7(), 'entities' => [$order()]])],
    'POST /sync/push (10 transaksi)' => [20, fn () => req('POST', "$base/sync/push", $dev, ['batch_id' => (string) Str::uuid7(), 'entities' => array_map(fn () => $order(), range(1, 10))])],
    'POST /sync/push (20 transaksi)' => [10, fn () => req('POST', "$base/sync/push", $dev, ['batch_id' => (string) Str::uuid7(), 'entities' => array_map(fn () => $order(), range(1, 20))])],
    'GET /sync/pull (snapshot penuh)' => [40, fn () => req('GET', "$base/sync/pull", $dev)],
    'GET /sync/pull (tanpa perubahan)' => [40, fn () => req('GET', "$base/sync/pull?since=$version", $dev)],
    'GET /orders (back-office)' => [40, fn () => req('GET', "$base/orders?per_page=50", $bo)],
    'GET /outlets/{id}/end-of-day' => [40, fn () => req('GET', "$base/outlets/{$outlet->id}/end-of-day", $bo)],
];
foreach ($cases as $name => [$n, $fn]) {
    $fn(); // pemanasan
    $times = [];
    for ($i = 0; $i < $n; $i++) {
        [$c, $ms, $body] = $fn();
        $rejected = collect($body['data']['results'] ?? [])->where('status', 'rejected');
        if ($c !== 200 || $rejected->isNotEmpty()) {
            echo "$name -> HTTP $c ".json_encode($rejected->first()['error'] ?? $body['errors'] ?? null)."\n";
            break;
        }
        $times[] = $ms;
    }
    sort($times);
    $p = fn ($q) => $times === [] ? 0 : $times[(int) floor(($q / 100) * (count($times) - 1))];
    printf("%-34s n=%-3d p50=%6.1f ms  p95=%6.1f ms\n", $name, count($times), $p(50), $p(95));
}
