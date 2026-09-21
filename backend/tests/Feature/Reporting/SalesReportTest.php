<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ReportFixture;

beforeEach(function () {
    $this->f = ReportFixture::build();
    $this->owner = $this->f->headers('owner');
});

function fetchReport(string $key, array $query, array $headers): array
{
    $response = test()->getJson('/api/v1/reports/'.$key.'?'.http_build_query($query), $headers);
    $response->assertOk();
    assertStandardEnvelope($response);

    return (array) $response->json('data');
}

/** @return array<string, array<string, mixed>> */
function rowsBy(array $table, string $field = 'label'): array
{
    $out = [];
    foreach ($table['rows'] as $row) {
        $out[(string) $row[$field]] = $row;
    }

    return $out;
}

function summaryOf(array $table): array
{
    $out = [];
    foreach ($table['summary'] as $s) {
        $out[$s['label']] = $s['value'];
    }

    return $out;
}

describe('definisi angka penjualan (ADR 0006)', function () {
    it('menghitung ringkasan hari 1: diskon, pajak, service, void tidak dihitung', function () {
        $day1 = fetchReport('sales.day', ['date_from' => ReportFixture::DAY1, 'date_to' => ReportFixture::DAY1], $this->owner);

        expect(summaryOf($day1))->toMatchArray([
            'Penjualan bersih' => '129200.00',
            'Transaksi' => '2',
            'Rata-rata/transaksi' => '64600.00',
            'Diskon' => '6800.00',
            'Refund' => '0.00',
            'Pajak' => '13566.00',
            'Service charge' => '6460.00',
            'Total diterima' => '149200.00',
        ]);
        expect($day1['rows'])->toHaveCount(1)
            ->and($day1['rows'][0])->toMatchArray(['orders' => 2, 'gross_sales' => '136000.00', 'discount' => '6800.00', 'net_sales' => '129200.00', 'share' => '100.00'])
            ->and($day1['totals']['net_sales'])->toBe('129200.00');
    });

    it('mencatat refund pada hari refund terjadi tanpa mengubah hari transaksi asal', function () {
        $day2 = fetchReport('sales.day', ['date_from' => ReportFixture::DAY2, 'date_to' => ReportFixture::DAY2], $this->owner);
        expect(summaryOf($day2))->toMatchArray([
            'Penjualan bersih' => '43000.00',
            'Transaksi' => '1',
            'Refund' => '25000.00',
            'Pajak' => '4515.00',
            'Service charge' => '2150.00',
            'Total diterima' => '49639.71',
        ]);

        $both = fetchReport('sales.day', ['date_from' => ReportFixture::DAY1, 'date_to' => ReportFixture::DAY2], $this->owner);
        $rows = rowsBy($both, 'label');
        expect($both['rows'])->toHaveCount(2)
            ->and(collect($both['rows'])->pluck('net_sales')->all())->toBe(['129200.00', '43000.00'])
            ->and(collect($both['rows'])->pluck('refund')->all())->toBe(['0.00', '25000.00'])
            ->and($both['totals']['net_sales'])->toBe('172200.00')
            ->and(summaryOf($both)['Penjualan bersih'])->toBe('172200.00');
        expect($rows)->toHaveCount(2);

        // Periode hari 1 tetap sama setelah refund terjadi.
        expect(summaryOf(fetchReport('sales.day', ['date_from' => ReportFixture::DAY1, 'date_to' => ReportFixture::DAY1], $this->owner))['Penjualan bersih'])->toBe('129200.00');
    });

    it('membagi penjualan bersih ke item sehingga jumlahnya sama dengan total transaksi', function () {
        $items = fetchReport('sales.item', ['date_from' => ReportFixture::DAY1, 'date_to' => ReportFixture::DAY2], $this->owner);
        $rows = rowsBy($items);

        expect($rows['Croissant'])->toMatchArray([
            'qty' => '6.000', 'refund_qty' => '1.000',
            'gross_sales' => '150000.00', 'discount' => '5000.00', 'refund' => '25000.00', 'net_sales' => '120000.00', 'share' => '69.69',
        ])->and($rows['Croissant']['category'])->toStartWith('Kopi')
            ->and($rows['Kopi Susu'])->toMatchArray([
                'qty' => '3.000', 'refund_qty' => '0.000', 'discount' => '1800.00', 'net_sales' => '52200.00',
            ])->and($items['totals']['net_sales'])->toBe('172200.00')
            ->and($items['rows'][0]['label'])->toBe('Croissant');
    });

    it('mengelompokkan per jam, hari, channel, kasir, outlet, brand, dan metode bayar', function () {
        $q = ['date_from' => ReportFixture::DAY1, 'date_to' => ReportFixture::DAY2];

        $hour = fetchReport('sales.hour', $q, $this->owner);
        // Refund mengikuti jam transaksi asal (A pukul 18.56).
        expect($hour['rows'])->toHaveCount(2)
            ->and($hour['rows'][0])->toMatchArray(['label' => '05.00–05.59', 'orders' => 1, 'net_sales' => '68000.00'])
            ->and($hour['rows'][1])->toMatchArray(['label' => '18.00–18.59', 'orders' => 2, 'refund' => '25000.00', 'net_sales' => '104200.00']);

        $weekday = rowsBy(fetchReport('sales.weekday', $q, $this->owner));
        expect(array_keys($weekday))->toBe(['Kamis', 'Jumat'])->and($weekday['Jumat']['net_sales'])->toBe('43000.00');

        $channel = fetchReport('sales.channel', $q, $this->owner);
        expect($channel['rows'])->toHaveCount(1)->and($channel['rows'][0]['net_sales'])->toBe('172200.00')
            ->and($channel['rows'][0]['label'])->not->toBe('dine_in');

        $cashier = fetchReport('sales.cashier', $q, $this->owner);
        $byKey = rowsBy($cashier, 'label');
        $cashierName = $this->f->pos->staff['cashier']['user']->name;
        $managerName = $this->f->pos->staff['manager']['user']->name;
        // Refund mengikuti kasir transaksi asal (A milik kasir).
        expect($byKey[$cashierName])->toMatchArray(['orders' => 2, 'net_sales' => '111000.00', 'refund' => '25000.00'])
            ->and($byKey[$managerName])->toMatchArray(['orders' => 1, 'net_sales' => '61200.00', 'discount' => '6800.00']);

        $outlet = fetchReport('sales.outlet', $q, $this->owner);
        expect($outlet['rows'])->toHaveCount(1)->and($outlet['rows'][0]['label'])->toBe($this->f->pos->outlet->name);
        expect(fetchReport('sales.brand', $q, $this->owner)['rows'][0]['net_sales'])->toBe('172200.00');

        $pay = fetchReport('sales.payment', $q, $this->owner);
        expect($pay['rows'][0])->toMatchArray(['label' => 'Tunai', 'count' => 3, 'amount' => '227700.00', 'refund' => '28860.29', 'net_received' => '198839.71', 'share' => '100.00'])
            ->and($pay['totals']['refund'])->toBe('28860.29');
    });

    it('menampilkan pajak & DPP per outlet dengan koreksi refund di periode refund (FR-RPT-05)', function () {
        $day1 = fetchReport('tax', ['date_from' => ReportFixture::DAY1, 'date_to' => ReportFixture::DAY1], $this->owner);
        expect($day1['rows'][0])->toMatchArray([
            'tax_name' => 'PB1 10%', 'orders' => 2, 'tax_base' => '135660.00', 'tax' => '13566.00',
            'refund_tax' => '0.00', 'net_tax' => '13566.00', 'service_charge' => '6460.00',
        ]);

        $day2 = fetchReport('tax', ['date_from' => ReportFixture::DAY2, 'date_to' => ReportFixture::DAY2], $this->owner);
        expect($day2['rows'][0])->toMatchArray([
            'orders' => 1, 'tax_base' => '71400.00', 'tax' => '7140.00',
            'refund_tax_base' => '-26250.00', 'refund_tax' => '-2625.00',
            'net_tax_base' => '45150.00', 'net_tax' => '4515.00', 'service_charge' => '2150.00',
        ])->and($day2['totals']['net_tax'])->toBe('4515.00');
    });

    it('menghitung laporan anti-fraud per pengguna (FR-RPT-04)', function () {
        $q = ['date_from' => ReportFixture::DAY1, 'date_to' => ReportFixture::DAY2];
        $rows = rowsBy(fetchReport('fraud', $q, $this->owner));
        $cashier = $rows[$this->f->pos->staff['cashier']['user']->name];
        $manager = $rows[$this->f->pos->staff['manager']['user']->name];

        expect($cashier)->toMatchArray([
            'orders' => 2, 'net_sales' => '111000.00', 'void_after_count' => 0, 'refund_count' => 0,
            'variance_shift_count' => 1, 'cash_short' => '-500.00', 'cash_over' => '0.00', 'exception_rate' => '0.00',
        ])->and($manager)->toMatchArray([
            'orders' => 1, 'net_sales' => '61200.00',
            'void_after_count' => 1, 'void_before_count' => 0, 'void_amount' => '78500.00',
            'refund_count' => 1, 'refund_amount' => '28860.29',
            'discount_count' => 1, 'discount_amount' => '6800.00',
            'drawer_open_count' => 1, 'exception_rate' => '65.10',
        ]);

        $events = $this->getJson('/api/v1/reports/fraud/events?'.http_build_query($q), $this->owner)->assertOk()->json('data');
        expect(collect($events)->pluck('type')->all())->toBe(['refund', 'drawer_open', 'cash_variance', 'void_after', 'discount'])
            ->and($events[0])->toMatchArray(['amount' => '28860.29', 'reason' => 'Croissant gosong', 'user' => $this->f->pos->staff['manager']['user']->name]);

        $onlyCashier = $this->getJson('/api/v1/reports/fraud/events?'.http_build_query($q + ['user_id' => $this->f->pos->userId('cashier')]), $this->owner)->json('data');
        expect(collect($onlyCashier)->pluck('type')->all())->toBe(['cash_variance']);
    });

    it('menyajikan dashboard hari ini dengan pembanding minggu lalu & kemarin (FR-RPT-01)', function () {
        $data = $this->getJson('/api/v1/dashboard', $this->owner)->assertOk()->json('data');

        expect($data['business_date'])->toBe(ReportFixture::DAY2)
            ->and($data['today'])->toMatchArray(['net_sales' => '43000.00', 'order_count' => 1, 'refund' => '25000.00'])
            ->and($data['yesterday'])->toMatchArray(['net_sales' => '129200.00', 'order_count' => 2])
            ->and($data['same_time_last_week']['net_sales'])->toBe('0.00')
            ->and($data['change']['net_sales'])->toBeNull()
            ->and($data['hourly'][0])->toBe(['hour' => 5, 'label' => '05.00', 'net_sales' => '68000.00', 'last_week' => '0.00', 'orders' => 1])
            ->and(end($data['hourly']))->toBe(['hour' => 18, 'label' => '18.00', 'net_sales' => '-25000.00', 'last_week' => '0.00', 'orders' => 0])
            ->and(collect($data['top_items'])->pluck('label')->all())->toBe(['Croissant', 'Kopi Susu'])
            ->and($data['payments'][0]['net_received'])->toBe('49639.71');
    });
});

it('membandingkan dengan minggu lalu hanya sampai jam yang sama', function () {
    $f = ReportFixture::build();
    // Satu minggu setelah hari 1, pukul 18.58: transaksi hari 1 (18.56) terhitung; pukul 18.50 belum.
    $this->travelTo(CarbonImmutable::parse('2026-09-17 18:58', 'Asia/Jakarta'));
    $early = $this->getJson('/api/v1/dashboard', $f->headers('owner'))->assertOk()->json('data');
    expect($early['same_time_last_week'])->toMatchArray(['net_sales' => '129200.00', 'order_count' => 2]);

    $this->travelTo(CarbonImmutable::parse('2026-09-17 18:50', 'Asia/Jakarta'));
    Cache::flush();
    $before = $this->getJson('/api/v1/dashboard', $f->headers('owner'))->assertOk()->json('data');
    expect($before['same_time_last_week'])->toMatchArray(['net_sales' => '0.00', 'order_count' => 0])
        ->and($before['today']['net_sales'])->toBe('0.00');
});
