<?php

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Tenancy\Domain\Models\Brand;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\Factory;
use Tests\Support\Pos;
use Tests\Support\ReportFixture;

beforeEach(function () {
    $this->f = ReportFixture::build();
    $this->company = $this->f->pos->company;
    $this->owner = $this->f->headers('owner');
    $this->q = ['date_from' => ReportFixture::DAY1, 'date_to' => ReportFixture::DAY2];
});

function reportUrl(string $key, array $query = []): string
{
    return '/api/v1/reports/'.$key.($query === [] ? '' : '?'.http_build_query($query));
}

it('menampilkan daftar laporan sesuai hak akses', function () {
    $keys = collect($this->getJson('/api/v1/reports', $this->owner)->assertOk()->json('data'))->pluck('key');
    expect($keys)->toContain('sales.day', 'sales.payment', 'tax', 'fraud', 'gross_profit', 'menu_engineering', 'inventory.movements');

    [$warehouse] = Factory::staff($this->company, ['warehouse']);
    $wk = collect($this->getJson('/api/v1/reports', asMember($warehouse, $this->company))->json('data'))->pluck('key');
    expect($wk->every(fn ($k) => str_starts_with($k, 'inventory.')))->toBeTrue()->and($wk)->not->toBeEmpty();
});

it('memvalidasi laporan, periode, dan format', function () {
    $this->getJson(reportUrl('sales.unknown', $this->q), $this->owner)->assertNotFound();
    $this->getJson('/api/v1/reports/tidak-ada', $this->owner)->assertNotFound();

    $bad = $this->getJson(reportUrl('sales.day', ['date_from' => '2026-09-12', 'date_to' => '2026-09-01']), $this->owner)->assertUnprocessable();
    expect(errorFields($bad))->toContain('date_to');
    $this->getJson(reportUrl('sales.day', ['date_from' => '10/09/2026']), $this->owner)->assertUnprocessable();
    $long = $this->getJson(reportUrl('sales.day', ['date_from' => '2025-01-01', 'date_to' => '2026-09-11']), $this->owner)->assertUnprocessable();
    expect(errorFields($long))->toContain('date_from');
    $this->getJson(reportUrl('sales.day', ['outlet_id' => 'bukan-uuid']), $this->owner)->assertUnprocessable();

    $this->get(reportUrl('tax/export', $this->q + ['format' => 'csv']), $this->owner)->assertUnprocessable();

    // Bawaan: awal bulan sampai hari ini (zona waktu company).
    $default = $this->getJson(reportUrl('sales.day'), $this->owner)->assertOk();
    expect($default->json('meta.filter'))->toMatchArray(['date_from' => '2026-09-01', 'date_to' => ReportFixture::DAY2]);
});

describe('hak akses & isolasi', function () {
    it('kasir tidak dapat membuka laporan maupun dashboard (403)', function () {
        $cashier = $this->f->headers('cashier');
        $this->getJson(reportUrl('sales.day', $this->q), $cashier)->assertForbidden();
        $this->getJson(reportUrl('inventory.stock'), $cashier)->assertForbidden();
        $this->getJson('/api/v1/dashboard', $cashier)->assertForbidden();
        $this->get(reportUrl('fraud/export', $this->q + ['format' => 'xlsx']), $cashier)->assertForbidden();
        expect($this->getJson('/api/v1/reports', $cashier)->json('data'))->toBe([]);
    });

    it('gudang hanya melihat laporan inventory', function () {
        [$warehouse] = Factory::staff($this->company, ['warehouse']);
        $h = asMember($warehouse, $this->company);
        $this->getJson(reportUrl('inventory.stock'), $h)->assertOk();
        $this->getJson(reportUrl('sales.day', $this->q), $h)->assertForbidden();
        $this->getJson(reportUrl('gross_profit', $this->q), $h)->assertForbidden();
    });

    it('manajer outlet hanya melihat outlet miliknya', function () {
        $pos = $this->f->pos;
        // Transaksi di outlet lain (Dago) tidak boleh terlihat oleh manajer Kemang.
        $other = Factory::outlet($this->company, $pos->tenant(fn () => Brand::query()->findOrFail($pos->outlet->brand_id)), ['code' => 'BDG']);
        [$otherManager] = Factory::staff($this->company, ['outlet_manager'], [$other->id]);
        $h = asMember($otherManager, $this->company);

        $table = $this->getJson(reportUrl('sales.day', $this->q), $h)->assertOk()->json('data');
        expect($table['rows'])->toBe([])->and($table['totals']['net_sales'])->toBe('0.00');
        $this->getJson(reportUrl('sales.day', $this->q + ['outlet_id' => $pos->outlet->id]), $h)->assertNotFound();
        expect($this->getJson('/api/v1/dashboard', $h)->json('data.today.net_sales'))->toBe('0.00');
        $this->getJson('/api/v1/dashboard?outlet_id='.$pos->outlet->id, $h)->assertNotFound();

        $mine = $this->getJson(reportUrl('sales.day', $this->q + ['outlet_id' => $pos->outlet->id]), $this->f->headers('manager'))->assertOk();
        expect($mine->json('data.totals.net_sales'))->toBe('172200.00')
            ->and($mine->json('data.filters.Outlet'))->toBe($pos->outlet->name);
    });

    it('filter brand/outlet dari company lain diperlakukan tidak ada dan data tidak bocor', function () {
        $other = Pos::setup('Bakmi Nusantara', 'BKM');
        $otherBrand = $other->tenant(fn () => Brand::query()->firstOrFail());

        $this->getJson(reportUrl('sales.item', $this->q + ['outlet_id' => $other->outlet->id]), $this->owner)->assertNotFound();
        $this->getJson(reportUrl('tax', $this->q + ['brand_id' => $otherBrand->id]), $this->owner)->assertNotFound();
        $this->get(reportUrl('tax/export', $this->q + ['format' => 'pdf', 'outlet_id' => $other->outlet->id]), $this->owner)->assertNotFound();
        $this->getJson('/api/v1/dashboard?brand_id='.$otherBrand->id, $this->owner)->assertNotFound();

        // Pemilik company lain tidak melihat transaksi company ini.
        $otherOwner = asMember(Factory::ownerOf($other->company), $other->company);
        foreach (['sales.day', 'sales.item', 'sales.payment', 'tax', 'fraud', 'fraud.events', 'gross_profit', 'menu_engineering'] as $key) {
            expect($this->getJson(reportUrl($key, $this->q), $otherOwner)->assertOk()->json('data.rows'))->toBe([], $key);
        }
        $this->getJson(reportUrl('sales.day', $this->q + ['outlet_id' => $this->f->pos->outlet->id]), $otherOwner)->assertNotFound();

        // Header company yang bukan keanggotaan ditolak.
        $this->getJson(reportUrl('sales.day'), ['X-Company-Id' => $other->company->id] + $this->owner)->assertForbidden();
    });
});

it('mengekspor Excel dan PDF dengan angka yang sama serta mencatat audit (FR-RPT-08)', function () {
    $xlsx = $this->get(reportUrl('sales.item/export', $this->q + ['format' => 'xlsx']), $this->owner)->assertOk();
    expect($xlsx->headers->get('content-type'))->toContain('spreadsheetml')
        ->and($xlsx->headers->get('content-disposition'))->toContain('laporan-penjualan-per-item-20260910-20260911.xlsx');
    $path = $xlsx->baseResponse->getFile()->getPathname();
    $reader = new Reader;
    $reader->open($path);
    $cells = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $cells[] = $row->toArray();
        }
    }
    $reader->close();
    $croissant = collect($cells)->first(fn ($r) => ($r[0] ?? null) === 'Croissant');
    $total = collect($cells)->first(fn ($r) => ($r[0] ?? null) === 'Total');
    expect($cells[0][0])->toBe('Laporan Penjualan — Per item')
        ->and($croissant[2])->toEqual(6)
        ->and($croissant[7])->toEqual(120000)
        ->and($total[7])->toEqual(172200);

    $pdf = $this->get(reportUrl('fraud/export', $this->q + ['format' => 'pdf']), $this->owner)->assertOk();
    expect($pdf->headers->get('content-type'))->toBe('application/pdf')
        ->and(file_get_contents($pdf->baseResponse->getFile()->getPathname()))->toStartWith('%PDF');

    @unlink($path);
    @unlink($pdf->baseResponse->getFile()->getPathname());

    $logs = $this->f->pos->tenant(fn () => AuditLog::query()->where('action', 'report.exported')->get())
        ->keyBy(fn (AuditLog $l) => $l->new_values['report']);
    expect($logs)->toHaveCount(2)
        ->and($logs['sales.item']->new_values)->toMatchArray(['format' => 'xlsx', 'rows' => 2, 'date_from' => ReportFixture::DAY1])
        ->and($logs['fraud']->new_values['format'])->toBe('pdf');
});

it('membatasi laju permintaan laporan', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->getJson(reportUrl('tax', $this->q), $this->owner)->assertOk();
    }
    $this->getJson(reportUrl('tax', $this->q), $this->owner)->assertStatus(429);
});
