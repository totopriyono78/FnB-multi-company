<?php

use App\Modules\Catalog\Domain\Pricing\PromotionEngine;

function bxgyPromo(int $buy, int $get): array
{
    return [
        'id' => 'bxgy', 'name' => 'bxgy', 'code' => null, 'brand_id' => null, 'type' => 'buy_x_get_y', 'scope' => 'items',
        'value' => '0', 'min_purchase' => null, 'max_discount' => null, 'buy_qty' => $buy, 'get_qty' => $get,
        'item_ids' => [], 'category_ids' => ['kopi'], 'outlet_ids' => [], 'channel_codes' => null, 'payment_methods' => null,
        'days_of_week' => null, 'time_start' => null, 'time_end' => null, 'starts_at' => '2026-01-01T00:00:00+07:00',
        'ends_at' => null, 'quota_remaining' => null, 'stackable' => false, 'auto_apply' => true, 'priority' => 0,
    ];
}

function bxgyContext(): array
{
    return ['outlet_id' => 'o', 'channel_code' => 'dine_in', 'local_time' => '2026-09-16 10:00', 'timezone' => 'Asia/Jakarta', 'payment_method' => null, 'codes' => []];
}

/** Referensi sederhana: urutkan setiap unit dari termahal, unit ke-(buy+1..buy+get) tiap kelompok gratis. */
function bxgyNaiveTotal(array $lines, int $buy, int $get): int
{
    $units = [];
    foreach ($lines as $order => $l) {
        for ($i = 0; $i < (int) $l['qty']; $i++) {
            $units[] = [(int) $l['unit_price'], $order];
        }
    }
    usort($units, fn ($a, $b) => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);
    $chunk = $buy + $get;
    $total = 0;
    for ($p = 0; $p < intdiv(count($units), $chunk) * $chunk; $p++) {
        if ($p % $chunk >= $buy) {
            $total += $units[$p][0];
        }
    }

    return $total;
}

it('menghitung beli X gratis Y sama dengan perhitungan per unit', function () {
    mt_srand(20260916);
    for ($case = 0; $case < 200; $case++) {
        $buy = mt_rand(1, 4);
        $get = mt_rand(1, 3);
        $lines = [];
        for ($i = 0, $n = mt_rand(1, 6); $i < $n; $i++) {
            $lines[] = ['id' => "L$i", 'item_id' => "i$i", 'category_id' => 'kopi', 'brand_id' => 'b',
                'unit_price' => (string) (mt_rand(5, 40) * 1000), 'qty' => (string) mt_rand(1, 7)];
        }
        $result = (new PromotionEngine)->apply(['lines' => $lines], bxgyContext(), [bxgyPromo($buy, $get)]);
        $amount = (int) round((float) ($result['applied'][0]['amount'] ?? 0));

        expect($amount)->toBe(bxgyNaiveTotal($lines, $buy, $get), "kasus $case (beli $buy gratis $get)");
    }
});

it('tetap cepat untuk jumlah besar', function () {
    $lines = [];
    for ($i = 0; $i < 200; $i++) {
        $lines[] = ['id' => "L$i", 'item_id' => "i$i", 'category_id' => 'kopi', 'brand_id' => 'b', 'unit_price' => (string) (10000 + $i), 'qty' => '9999'];
    }
    $start = microtime(true);
    $memory = memory_get_usage();
    $result = (new PromotionEngine)->apply(['lines' => $lines], bxgyContext(), [bxgyPromo(1, 1)]);

    expect(microtime(true) - $start)->toBeLessThan(1.0)
        ->and(memory_get_usage() - $memory)->toBeLessThan(10 * 1024 * 1024)
        ->and($result['applied'])->toHaveCount(1);
});
