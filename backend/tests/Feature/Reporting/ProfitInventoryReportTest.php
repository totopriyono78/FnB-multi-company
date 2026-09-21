<?php

use App\Modules\Inventory\Domain\Models\Recipe;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Tests\Support\Factory;
use Tests\Support\Pos;
use Tests\Support\Stock;

/**
 * Transaksi standar (2 Croissant + 1 Kopi Regular): penjualan bersih 68.000.
 * HPP: Croissant 2 × 9.000 + Kopi (18 g × 250 + 150 ml × 18) = 18.000 + 7.200 = 25.200.
 * Waste susu 100 ml × 18 = 1.800; penyesuaian hilang 1 Croissant = 9.000.
 */
beforeEach(function () {
    $this->pos = Pos::setup();
    $company = $this->pos->company;
    $this->pos->tenant(fn () => Outlet::query()->whereKey($this->pos->outlet->id)->update(['stock_deduction_trigger' => 'on_payment']));
    $this->location = Stock::location($company, $this->pos->outlet);
    $this->beans = Stock::ingredient($company, 'Biji Kopi Arabika Gayo', 'g');
    $this->milk = Stock::ingredient($company, 'Susu Segar', 'ml');
    $this->dough = Stock::ingredient($company, 'Croissant Beku', 'pcs');
    Stock::recipe($company, Recipe::ITEM, $this->pos->coffee->id, [[$this->beans, '18'], [$this->milk, '150']]);
    Stock::recipe($company, Recipe::ITEM, $this->pos->croissant->id, [[$this->dough, '1']]);
    Stock::receive($company, $this->location, $this->beans, '1000', '250');
    Stock::receive($company, $this->location, $this->milk, '5000', '18');
    Stock::receive($company, $this->location, $this->dough, '20', '9000');
    [$shiftId, $ymd] = $this->pos->openShift();
    expect($this->pos->push('order', $this->pos->order($shiftId, $this->pos->receipt($ymd, 1)))['status'])->toBe('accepted');

    $this->owner = asMember(Factory::ownerOf($company), $company);
    $post = fn (string $type, string $reason, array $lines) => $this->postJson('/api/v1/stock-adjustments', [
        'location_id' => $this->location->id, 'type' => $type, 'reason_code' => $reason, 'lines' => $lines,
    ], $this->owner)->assertCreated();
    $post('waste', 'spilled', [['ingredient_id' => $this->milk->id, 'qty' => '100']]);
    $post('adjustment', 'lost', [['ingredient_id' => $this->dough->id, 'qty' => '-1']]);

    $today = now()->setTimezone('Asia/Jakarta')->format('Y-m-d');
    $this->q = ['date_from' => $today, 'date_to' => $today];
});

it('menghitung laba kotor per outlet dengan waste & selisih terpisah (FR-RPT-07)', function () {
    $table = $this->getJson('/api/v1/reports/gross_profit?'.http_build_query($this->q), $this->owner)->assertOk()->json('data');

    expect($table['rows'])->toHaveCount(1)
        ->and($table['rows'][0])->toMatchArray([
            'label' => $this->pos->outlet->name,
            'net_sales' => '68000.00',
            'cogs' => '25200.00',
            'gross_profit' => '42800.00',
            'gross_margin' => '62.94',
            'waste' => '1800.00',
            'variance' => '9000.00',
            'profit_after' => '32000.00',
            'food_cost' => '52.94',
        ])
        ->and($table['totals']['profit_after'])->toBe('32000.00');
});

it('mengelompokkan menu dengan metode menu engineering (FR-RPT-03)', function () {
    $table = $this->getJson('/api/v1/reports/menu_engineering?'.http_build_query($this->q), $this->owner)->assertOk()->json('data');
    $rows = collect($table['rows'])->keyBy('label');

    expect($rows['Croissant'])->toMatchArray([
        'rank' => 1, 'qty' => '2.000', 'menu_mix' => '66.67', 'avg_price' => '25000.00',
        'unit_cost' => '9000.00', 'unit_margin' => '16000.00', 'total_margin' => '32000.00',
        'class_label' => 'Star', 'basis_label' => 'Pemakaian tercatat',
    ])->and($rows['Kopi Susu'])->toMatchArray([
        'rank' => 2, 'qty' => '1.000', 'unit_cost' => '7200.00', 'unit_margin' => '10800.00', 'class_label' => 'Dog',
    ]);
    expect(collect($table['summary'])->pluck('value', 'label')->all())->toMatchArray(['Star' => '1', 'Dog' => '1', 'Plowhorse' => '0', 'Puzzle' => '0']);
});

it('menyajikan laporan inventory: mutasi, waste, posisi stok (FR-RPT-06)', function () {
    $moves = collect($this->getJson('/api/v1/reports/inventory.movements?'.http_build_query($this->q), $this->owner)->assertOk()->json('data.rows'))->keyBy('label');
    expect($moves['Croissant Beku'])->toMatchArray([
        'opening_qty' => '0.000', 'in_qty' => '20.000', 'sale_qty' => '2.000', 'waste_qty' => '0.000',
        'adjust_qty' => '-1.000', 'closing_qty' => '17.000', 'usage_value' => '18000.00', 'closing_value' => '153000.00',
    ])->and($moves['Susu Segar'])->toMatchArray(['sale_qty' => '150.000', 'waste_qty' => '100.000', 'closing_qty' => '4750.000']);

    // Periode besok: saldo awal = saldo akhir hari ini.
    $tomorrow = now()->setTimezone('Asia/Jakarta')->addDay()->format('Y-m-d');
    $next = collect($this->getJson('/api/v1/reports/inventory.movements?date_from='.$tomorrow.'&date_to='.$tomorrow, $this->owner)->json('data.rows'))->keyBy('label');
    expect($next['Croissant Beku'])->toMatchArray(['opening_qty' => '17.000', 'in_qty' => '0.000', 'closing_qty' => '17.000', 'opening_value' => '153000.00']);

    $waste = $this->getJson('/api/v1/reports/inventory.waste?'.http_build_query($this->q), $this->owner)->json('data');
    expect($waste['rows'])->toHaveCount(1)
        ->and($waste['rows'][0])->toMatchArray(['label' => 'Susu Segar', 'reason' => 'Tumpah / jatuh', 'qty' => '100.000', 'unit' => 'ml', 'value' => '1800.00']);

    $stock = collect($this->getJson('/api/v1/reports/inventory.stock', $this->owner)->json('data.rows'))->keyBy('label');
    expect($stock['Croissant Beku'])->toMatchArray(['qty' => '17.000', 'avg_cost' => '9000.00', 'value' => '153000.00', 'status' => 'Aman']);

    $counts = $this->getJson('/api/v1/reports/inventory.counts?'.http_build_query($this->q), $this->owner)->json('data');
    expect($counts['rows'])->toBe([])->and($counts['totals']['variance_value'])->toBe('0.00');
});
