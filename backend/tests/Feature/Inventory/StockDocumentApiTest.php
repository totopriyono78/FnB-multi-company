<?php

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Factory;
use Tests\Support\Pos;
use Tests\Support\Stock;

beforeEach(function () {
    $this->pos = Pos::setup();
    $company = $this->pos->company;
    $this->owner = Factory::ownerOf($company);
    $this->headers = asMember($this->owner, $company);
    $this->main = Stock::location($company, $this->pos->outlet);
    $this->dago = Stock::location($company, $this->pos->otherOutlet);
    $this->beans = Stock::ingredient($company, 'Biji Kopi Arabika Gayo', 'g', ['min_stock' => '500']);
    $this->milk = Stock::ingredient($company, 'Susu Segar', 'ml');
    $this->managerHeaders = asMember($this->pos->staff['manager']['user'], $company);
});

function adjust(array $headers, string $locationId, string $type, string $reason, array $lines, array $extra = []): TestResponse
{
    return test()->postJson('/api/v1/stock-adjustments', ['location_id' => $locationId, 'type' => $type, 'reason_code' => $reason, 'lines' => $lines] + $extra, $headers);
}

describe('penyesuaian & waste (FR-INV-05)', function () {
    it('mencatat saldo awal, waste, dan koreksi dengan nomor dokumen berurutan', function () {
        $opening = adjust($this->headers, $this->main->id, 'adjustment', 'opening', [
            ['ingredient_id' => $this->beans->id, 'qty' => '2000', 'unit_cost' => '250'],
            ['ingredient_id' => $this->milk->id, 'qty' => '5000', 'unit_cost' => '18'],
        ])->assertCreated();
        $opening->assertJsonPath('data.number', 'ADJ-KMG-'.now()->setTimezone('Asia/Jakarta')->format('ym').'-0001')
            ->assertJsonPath('data.total_value', '590000.00')
            ->assertJsonPath('data.reason_label', 'Saldo awal')
            ->assertJsonCount(2, 'data.lines');

        $waste = adjust($this->managerHeaders, $this->main->id, 'waste', 'spilled', [['ingredient_id' => $this->milk->id, 'qty' => '250', 'note' => 'Tumpah saat steaming']])->assertCreated();
        $waste->assertJsonPath('data.number', 'WST-KMG-'.now()->setTimezone('Asia/Jakarta')->format('ym').'-0001')
            ->assertJsonPath('data.total_value', '-4500.00')
            ->assertJsonPath('data.lines.0.qty', '-250.0000');

        adjust($this->headers, $this->main->id, 'adjustment', 'correction', [['ingredient_id' => $this->beans->id, 'qty' => '-100']])
            ->assertCreated()->assertJsonPath('data.number', 'ADJ-KMG-'.now()->setTimezone('Asia/Jakarta')->format('ym').'-0002');

        $balances = $this->getJson('/api/v1/stock/balances?location_id='.$this->main->id, $this->headers)->assertOk();
        $balances->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.0.ingredient.name', 'Biji Kopi Arabika Gayo')
            ->assertJsonPath('data.0.qty', '1900.0000')
            ->assertJsonPath('data.0.value', '475000.00')
            ->assertJsonPath('data.0.below_minimum', false)
            ->assertJsonPath('data.1.qty', '4750.0000');

        $card = $this->getJson('/api/v1/stock/movements?ingredient_id='.$this->milk->id, $this->headers)->assertOk();
        $card->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.0.type', 'waste')
            ->assertJsonPath('data.0.type_label', 'Waste')
            ->assertJsonPath('data.0.balance_after', '4750.0000')
            ->assertJsonPath('data.0.reason', 'Tumpah / jatuh · Tumpah saat steaming')
            ->assertJsonPath('data.1.flags', ['opening']);

        expect($this->pos->tenant(fn () => AuditLog::query()->whereIn('action', ['stock.adjusted', 'stock.waste_recorded'])->count()))->toBe(3);
        $this->getJson('/api/v1/stock-adjustments?type=waste', $this->headers)->assertOk()->assertJsonPath('meta.pagination.total', 1);
        $this->getJson('/api/v1/stock-adjustments/'.$waste->json('data.id'), $this->managerHeaders)->assertOk()->assertJsonCount(1, 'data.lines');

        // Kartu stok append-only.
        expect(fn () => $this->pos->tenant(fn () => StockMovement::query()->limit(1)->update(['qty' => 1])))->toThrow(QueryException::class);
        expect(fn () => $this->pos->tenant(fn () => DB::table('stock_adjustments')->delete()))->toThrow(QueryException::class);
    });

    it('menyaring stok di bawah minimum', function () {
        adjust($this->headers, $this->main->id, 'adjustment', 'opening', [['ingredient_id' => $this->beans->id, 'qty' => '400', 'unit_cost' => '250']])->assertCreated();
        $this->getJson('/api/v1/stock/balances?below_minimum=1', $this->headers)
            ->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.below_minimum', true)->assertJsonPath('data.0.min_qty', '500.0000');
    });

    it('menolak stok minus di outlet yang tidak mengizinkannya', function () {
        $this->pos->tenant(fn () => Outlet::query()->whereKey($this->pos->outlet->id)->update(['allow_negative_stock' => false]));
        adjust($this->headers, $this->main->id, 'waste', 'expired', [['ingredient_id' => $this->milk->id, 'qty' => '10']])
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'STOCK_INSUFFICIENT')->assertJsonPath('errors.0.details.available', '0.0000');
        expect($this->pos->tenant(fn () => StockMovement::query()->count()))->toBe(0);
        // Nomor dokumen dari percobaan yang gagal tidak terpakai.
        $this->pos->tenant(fn () => Outlet::query()->whereKey($this->pos->outlet->id)->update(['allow_negative_stock' => true]));
        adjust($this->headers, $this->main->id, 'waste', 'expired', [['ingredient_id' => $this->milk->id, 'qty' => '10']])
            ->assertCreated()->assertJsonPath('data.number', 'WST-KMG-'.now()->setTimezone('Asia/Jakarta')->format('ym').'-0001')
            ->assertJsonPath('data.lines.0.qty', '-10.0000');
    });

    it('memvalidasi alasan, jumlah, dan waktu', function () {
        adjust($this->headers, $this->main->id, 'waste', 'opening', [['ingredient_id' => $this->milk->id, 'qty' => '1']])
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'INVALID_REASON');
        adjust($this->headers, $this->main->id, 'waste', 'other', [['ingredient_id' => $this->milk->id, 'qty' => '1']])
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'NOTES_REQUIRED');
        adjust($this->headers, $this->main->id, 'waste', 'damaged', [['ingredient_id' => $this->milk->id, 'qty' => '-1']])
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'INVALID_QTY');
        adjust($this->headers, $this->main->id, 'adjustment', 'found', [['ingredient_id' => $this->milk->id, 'qty' => '1'], ['ingredient_id' => $this->milk->id, 'qty' => '2']])
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'DUPLICATE_INGREDIENT');
        adjust($this->headers, $this->main->id, 'adjustment', 'found', [['ingredient_id' => $this->milk->id, 'qty' => '1']], ['occurred_at' => now()->subDays(10)->toIso8601String()])
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'TOO_OLD');
        $bad = adjust($this->headers, 'bukan-uuid', 'hilang', '', [['ingredient_id' => 'x', 'qty' => 'abc']]);
        $bad->assertUnprocessable();
        expect(errorFields($bad))->toContain('location_id', 'type', 'reason_code', 'lines.0.ingredient_id', 'lines.0.qty');
    });

    it('membatasi penyesuaian sesuai izin & cakupan outlet', function () {
        $body = [['ingredient_id' => $this->milk->id, 'qty' => '1']];
        // Manajer KMG tidak dapat menyesuaikan stok Dago.
        adjust($this->managerHeaders, $this->dago->id, 'adjustment', 'found', $body)->assertForbidden();
        // Manajer brand & finance hanya melihat; kasir tanpa akses.
        [$finance] = Factory::staff($this->pos->company, ['finance']);
        adjust(asMember($finance, $this->pos->company), $this->main->id, 'adjustment', 'found', $body)->assertForbidden();
        $this->getJson('/api/v1/stock/balances', asMember($finance, $this->pos->company))->assertOk();
        $this->getJson('/api/v1/stock/balances', asMember($this->pos->staff['cashier']['user'], $this->pos->company))->assertForbidden();

        $doc = adjust($this->headers, $this->dago->id, 'adjustment', 'found', $body)->assertCreated();
        $this->getJson('/api/v1/stock-adjustments/'.$doc->json('data.id'), $this->managerHeaders)->assertNotFound();
        $this->getJson('/api/v1/stock/movements', $this->managerHeaders)->assertOk()->assertJsonPath('meta.pagination.total', 0);
    });
});

describe('transfer antar lokasi (FR-INV-05)', function () {
    beforeEach(function () {
        adjust($this->headers, $this->main->id, 'adjustment', 'opening', [
            ['ingredient_id' => $this->beans->id, 'qty' => '2000', 'unit_cost' => '250'],
            ['ingredient_id' => $this->milk->id, 'qty' => '5000', 'unit_cost' => '18'],
        ])->assertCreated();
        [$this->dagoManager] = Factory::staff($this->pos->company, ['outlet_manager'], [$this->pos->otherOutlet->id]);
        $this->dagoHeaders = asMember($this->dagoManager, $this->pos->company);
    });

    it('mengirim, menerima sebagian dengan HPP asal, dan mencatat selisih', function () {
        $sent = $this->postJson('/api/v1/stock-transfers', [
            'from_location_id' => $this->main->id, 'to_location_id' => $this->dago->id, 'notes' => 'Stok untuk akhir pekan',
            'lines' => [['ingredient_id' => $this->beans->id, 'qty' => '1000'], ['ingredient_id' => $this->milk->id, 'qty' => '2000']],
        ], $this->managerHeaders)->assertCreated();
        $sent->assertJsonPath('data.status', 'in_transit')->assertJsonPath('data.total_value', '286000.00');
        $id = $sent->json('data.id');
        $milkLine = collect($sent->json('data.lines'))->firstWhere('ingredient_id', $this->milk->id)['id'];
        expect(Stock::balance($this->pos->company, $this->main, $this->beans)['qty'])->toBe('1000.0000');

        // Pengirim tidak berwenang menerima di outlet tujuan.
        $this->postJson("/api/v1/stock-transfers/{$id}/receive", [], $this->managerHeaders)->assertForbidden();

        $this->postJson("/api/v1/stock-transfers/{$id}/receive", [
            'note' => 'Susu 1 kotak bocor', 'lines' => [['line_id' => $milkLine, 'qty_received' => '1000']],
        ], $this->dagoHeaders)->assertOk()->assertJsonPath('data.status', 'received');

        expect(Stock::balance($this->pos->company, $this->dago, $this->beans))->toBe(['qty' => '1000.0000', 'avg_cost' => '250.000000'])
            ->and(Stock::balance($this->pos->company, $this->dago, $this->milk)['qty'])->toBe('1000.0000');
        $log = $this->pos->tenant(fn () => AuditLog::query()->where('action', 'stock.transfer_received')->sole());
        expect($log->new_values['variance'])->toBeTrue();

        $this->postJson("/api/v1/stock-transfers/{$id}/receive", [], $this->dagoHeaders)->assertStatus(409)->assertJsonPath('errors.0.code', 'TRANSFER_NOT_IN_TRANSIT');
        $this->postJson("/api/v1/stock-transfers/{$id}/cancel", ['reason' => 'Salah kirim'], $this->managerHeaders)->assertStatus(409);
        $this->getJson('/api/v1/stock-transfers?outlet_id='.$this->pos->otherOutlet->id.'&direction=in', $this->dagoHeaders)
            ->assertOk()->assertJsonPath('meta.pagination.total', 1);
    });

    it('membatalkan transfer mengembalikan stok ke asal; jumlah terima tidak boleh melebihi kiriman', function () {
        $sent = $this->postJson('/api/v1/stock-transfers', [
            'from_location_id' => $this->main->id, 'to_location_id' => $this->dago->id,
            'lines' => [['ingredient_id' => $this->beans->id, 'qty' => '500']],
        ], $this->headers)->assertCreated();
        $id = $sent->json('data.id');

        $this->postJson("/api/v1/stock-transfers/{$id}/receive", ['lines' => [['line_id' => $sent->json('data.lines.0.id'), 'qty_received' => '600']]], $this->dagoHeaders)
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'INVALID_QTY');
        $this->postJson("/api/v1/stock-transfers/{$id}/cancel", ['reason' => 'Kurir batal'], $this->headers)->assertOk()->assertJsonPath('data.status', 'cancelled');
        expect(Stock::balance($this->pos->company, $this->main, $this->beans)['qty'])->toBe('2000.0000');

        $this->postJson('/api/v1/stock-transfers', ['from_location_id' => $this->main->id, 'to_location_id' => $this->main->id, 'lines' => [['ingredient_id' => $this->beans->id, 'qty' => '1']]], $this->headers)
            ->assertUnprocessable();
    });

    it('isolasi tenant untuk transfer & lokasi', function () {
        [$other, $otherOwner] = Factory::company('Warung Bu Ratna');
        $h = asMember($otherOwner, $other);
        $foreignLocation = Stock::location($other, Factory::outlet($other));
        $this->postJson('/api/v1/stock-transfers', [
            'from_location_id' => $foreignLocation->id, 'to_location_id' => $this->main->id,
            'lines' => [['ingredient_id' => $this->beans->id, 'qty' => '1']],
        ], $h)->assertUnprocessable()->assertJsonPath('errors.0.code', 'LOCATION_UNKNOWN');

        $sent = $this->postJson('/api/v1/stock-transfers', ['from_location_id' => $this->main->id, 'to_location_id' => $this->dago->id, 'lines' => [['ingredient_id' => $this->beans->id, 'qty' => '1']]], $this->headers)->assertCreated();
        $this->getJson('/api/v1/stock-transfers/'.$sent->json('data.id'), $h)->assertNotFound();
        $this->postJson('/api/v1/stock-transfers/'.$sent->json('data.id').'/receive', [], $h)->assertNotFound();
        $this->getJson('/api/v1/stock/balances', $h)->assertOk()->assertJsonPath('meta.pagination.total', 0);
    });
});

describe('stock opname (FR-INV-06)', function () {
    beforeEach(function () {
        adjust($this->headers, $this->main->id, 'adjustment', 'opening', [
            ['ingredient_id' => $this->beans->id, 'qty' => '2000', 'unit_cost' => '250'],
            ['ingredient_id' => $this->milk->id, 'qty' => '5000', 'unit_cost' => '18'],
        ])->assertCreated();
        [$this->warehouse] = Factory::staff($this->pos->company, ['warehouse']);
        $this->warehouseHeaders = asMember($this->warehouse, $this->pos->company);
    });

    it('membekukan saldo, menghitung selisih, dan memposting setelah disetujui manajer', function () {
        $start = $this->postJson('/api/v1/stock-counts', ['location_id' => $this->main->id, 'scope' => 'full', 'notes' => 'Opname akhir bulan'], $this->warehouseHeaders)->assertCreated();
        $start->assertJsonPath('data.status', 'counting')->assertJsonCount(2, 'data.lines');
        $id = $start->json('data.id');

        // Penjualan/mutasi setelah opname dimulai tidak mengubah saldo teoritis yang dibekukan.
        adjust($this->headers, $this->main->id, 'waste', 'spilled', [['ingredient_id' => $this->milk->id, 'qty' => '100']])->assertCreated();

        $this->postJson('/api/v1/stock-counts', ['location_id' => $this->main->id, 'scope' => 'full'], $this->warehouseHeaders)
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'COUNT_IN_PROGRESS');

        $this->putJson("/api/v1/stock-counts/{$id}/lines", ['lines' => [['ingredient_id' => $this->beans->id, 'counted_qty' => '1950']]], $this->warehouseHeaders)->assertOk();
        $this->postJson("/api/v1/stock-counts/{$id}/submit", [], $this->warehouseHeaders)
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'COUNT_INCOMPLETE')->assertJsonPath('errors.0.details.uncounted', 1);

        $this->putJson("/api/v1/stock-counts/{$id}/lines", ['lines' => [['ingredient_id' => $this->milk->id, 'counted_qty' => '5020', 'note' => 'Ada 1 kotak di chiller']]], $this->warehouseHeaders)->assertOk();
        $this->postJson("/api/v1/stock-counts/{$id}/submit", [], $this->warehouseHeaders)
            ->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.variance_value', '-12140.00')
            ->assertJsonPath('data.lines.0.system_qty', '2000.0000');

        // Gudang tidak dapat menyetujui; hasil hitung terkunci setelah diajukan.
        $this->postJson("/api/v1/stock-counts/{$id}/approve", [], $this->warehouseHeaders)->assertForbidden();
        $this->putJson("/api/v1/stock-counts/{$id}/lines", ['lines' => [['ingredient_id' => $this->milk->id, 'counted_qty' => '1']]], $this->warehouseHeaders)
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'COUNT_NOT_EDITABLE');

        // Manajer minta hitung ulang, lalu menyetujui.
        $this->postJson("/api/v1/stock-counts/{$id}/recount", ['note' => 'Cek ulang susu'], $this->managerHeaders)->assertOk()->assertJsonPath('data.status', 'counting');
        $this->postJson("/api/v1/stock-counts/{$id}/submit", [], $this->warehouseHeaders)->assertOk();
        $this->postJson("/api/v1/stock-counts/{$id}/approve", ['note' => 'Sesuai'], $this->managerHeaders)->assertOk()->assertJsonPath('data.status', 'approved');

        // Kopi: 2000 → 1950 (−50); susu: dibekukan 5000, fisik 5020 → +20 (setelah waste 100: 4900 + 20 = 4920).
        expect(Stock::balance($this->pos->company, $this->main, $this->beans)['qty'])->toBe('1950.0000')
            ->and(Stock::balance($this->pos->company, $this->main, $this->milk)['qty'])->toBe('4920.0000');
        $moves = $this->getJson('/api/v1/stock/movements?type=count', $this->headers)->assertOk();
        $moves->assertJsonPath('meta.pagination.total', 2);
        $log = $this->pos->tenant(fn () => AuditLog::query()->where('action', 'stock.count_approved')->sole());
        expect($log->new_values['self_approved'])->toBeFalse()->and($log->new_values['adjusted_lines'])->toBe(2);

        $this->postJson("/api/v1/stock-counts/{$id}/cancel", ['reason' => 'Batal'], $this->warehouseHeaders)->assertStatus(409);
        $this->getJson('/api/v1/stock-counts?status=approved', $this->headers)->assertOk()->assertJsonPath('meta.pagination.total', 1);
    });

    it('opname sebagian hanya untuk bahan yang dipilih dan dapat dibatalkan', function () {
        $start = $this->postJson('/api/v1/stock-counts', ['location_id' => $this->main->id, 'scope' => 'partial', 'ingredient_ids' => [$this->milk->id]], $this->managerHeaders)->assertCreated();
        // Hitung buta: saldo sistem tidak dikirim selama tahap hitung.
        $start->assertJsonCount(1, 'data.lines')->assertJsonPath('data.lines.0.system_qty', null);
        expect($this->pos->tenant(fn () => DB::table('stock_count_lines')->value('system_qty')))->toBe('5000.0000');
        $this->putJson('/api/v1/stock-counts/'.$start->json('data.id').'/lines', ['lines' => [['ingredient_id' => $this->beans->id, 'counted_qty' => '1']]], $this->managerHeaders)
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'LINE_UNKNOWN');
        $this->postJson('/api/v1/stock-counts/'.$start->json('data.id').'/cancel', ['reason' => 'Ganti jadwal'], $this->managerHeaders)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson('/api/v1/stock-counts', ['location_id' => $this->main->id, 'scope' => 'partial'], $this->managerHeaders)->assertUnprocessable();
    });

    it('opname outlet lain dan company lain tidak dapat diakses', function () {
        $start = $this->postJson('/api/v1/stock-counts', ['location_id' => $this->main->id, 'scope' => 'full'], $this->headers)->assertCreated();
        [$dagoManager] = Factory::staff($this->pos->company, ['outlet_manager'], [$this->pos->otherOutlet->id]);
        $this->getJson('/api/v1/stock-counts/'.$start->json('data.id'), asMember($dagoManager, $this->pos->company))->assertNotFound();
        $this->postJson('/api/v1/stock-counts', ['location_id' => $this->main->id, 'scope' => 'full'], asMember($dagoManager, $this->pos->company))->assertForbidden();

        [$other, $otherOwner] = Factory::company('Warung Bu Ratna');
        $this->getJson('/api/v1/stock-counts/'.$start->json('data.id'), asMember($otherOwner, $other))->assertNotFound();
        $this->postJson('/api/v1/stock-counts/'.$start->json('data.id').'/approve', [], asMember($otherOwner, $other))->assertNotFound();
        expect(Factory::tenant($other, fn () => AuditLog::query()->where('action', 'security.cross_tenant_access')->exists()))->toBeTrue();
    });
});
