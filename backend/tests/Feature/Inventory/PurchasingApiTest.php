<?php

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\Recipe;
use App\Modules\Purchasing\Domain\Models\PurchaseOrder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Factory;
use Tests\Support\Pos;
use Tests\Support\Stock;

beforeEach(function () {
    $this->pos = Pos::setup();
    $company = $this->pos->company;
    $this->owner = Factory::ownerOf($company);
    $this->headers = asMember($this->owner, $company);
    $this->main = Stock::location($company, $this->pos->outlet);
    $this->milk = Stock::ingredient($company, 'Susu UHT Full Cream', 'ml', [], ['karton' => '12000', 'kotak' => '1000']);
    $this->beans = Stock::ingredient($company, 'Biji Kopi Arabika Gayo', 'g', [], ['kg' => '1000']);
    [$this->warehouse] = Factory::staff($company, ['warehouse']);
    $this->warehouseHeaders = asMember($this->warehouse, $company);
    $this->managerHeaders = asMember($this->pos->staff['manager']['user'], $company);
    $this->supplierId = $this->postJson('/api/v1/suppliers', [
        'code' => 'SUP-001', 'name' => 'CV Sumber Susu Lembang', 'contact_name' => 'Ibu Wulan', 'phone' => '0812-2233-4455',
        'email' => 'order@sumbersusu.test', 'payment_term_days' => 14,
    ], $this->warehouseHeaders)->assertCreated()->json('data.id');
});

function poBody(string $locationId, string $supplierId, array $lines): array
{
    return ['location_id' => $locationId, 'supplier_id' => $supplierId, 'expected_date' => now()->addDays(2)->format('Y-m-d'), 'lines' => $lines];
}

describe('pemasok', function () {
    it('mengelola pemasok dengan validasi & hak akses', function () {
        $this->getJson('/api/v1/suppliers?search=lembang', $this->managerHeaders)->assertOk()->assertJsonPath('data.0.payment_term_days', 14);
        $this->postJson('/api/v1/suppliers', ['code' => 'sup-001', 'name' => 'Duplikat'], $this->headers)->assertUnprocessable()->assertJsonPath('errors.0.field', 'code');
        $bad = $this->postJson('/api/v1/suppliers', ['code' => 'A B', 'name' => '', 'email' => 'bukan-email', 'phone' => 'abc', 'payment_term_days' => 400], $this->headers)->assertUnprocessable();
        expect(errorFields($bad))->toContain('code', 'name', 'email', 'phone', 'payment_term_days');

        // Manajer outlet hanya mengajukan PO, tidak mengelola pemasok; kasir tanpa akses.
        $this->postJson('/api/v1/suppliers', ['code' => 'SUP-X', 'name' => 'Toko X'], $this->managerHeaders)->assertForbidden();
        $this->getJson('/api/v1/suppliers', asMember($this->pos->staff['cashier']['user'], $this->pos->company))->assertForbidden();

        $this->patchJson("/api/v1/suppliers/{$this->supplierId}", ['payment_term_days' => 30], $this->warehouseHeaders)->assertOk()->assertJsonPath('data.payment_term_days', 30);
        expect($this->pos->tenant(fn () => AuditLog::query()->where('action', 'supplier.updated')->count()))->toBe(1);

        [$other, $otherOwner] = Factory::company('Warung Bu Ratna');
        $this->getJson("/api/v1/suppliers/{$this->supplierId}", asMember($otherOwner, $other))->assertNotFound();
        $this->deleteJson("/api/v1/suppliers/{$this->supplierId}", [], asMember($otherOwner, $other))->assertNotFound();
    });
});

describe('purchase order (FR-PUR)', function () {
    it('alur ajukan → setujui → terima sebagian → terima lengkap memperbarui stok & HPP', function () {
        Stock::receive($this->pos->company, $this->main, $this->milk, '6000', '16');

        $created = $this->postJson('/api/v1/purchase-orders', poBody($this->main->id, $this->supplierId, [
            ['ingredient_id' => $this->milk->id, 'unit_name' => 'karton', 'qty' => '2', 'unit_price' => '222000'],
            ['ingredient_id' => $this->beans->id, 'unit_name' => 'kg', 'qty' => '1.5', 'unit_price' => '260000'],
        ]), $this->managerHeaders)->assertCreated();
        $created->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total', '834000.00')
            ->assertJsonPath('data.number', 'PO-KMG-'.now()->setTimezone('Asia/Jakarta')->format('ym').'-0001')
            ->assertJsonPath('data.supplier_name', 'CV Sumber Susu Lembang')
            ->assertJsonPath('data.lines.0.unit_factor', '12000.0000');
        $id = $created->json('data.id');
        [$milkLine, $beanLine] = [$created->json('data.lines.0.id'), $created->json('data.lines.1.id')];

        // Draf bisa diubah pengaju; belum bisa diterima.
        $this->patchJson("/api/v1/purchase-orders/{$id}", ['notes' => 'Kirim pagi sebelum jam 9'], $this->managerHeaders)->assertOk();
        $this->postJson("/api/v1/purchase-orders/{$id}/receipts", ['lines' => [['purchase_order_line_id' => $milkLine, 'qty' => '1']]], $this->warehouseHeaders)
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'PO_NOT_RECEIVABLE');

        $this->postJson("/api/v1/purchase-orders/{$id}/submit", [], $this->managerHeaders)->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->patchJson("/api/v1/purchase-orders/{$id}", ['notes' => 'x'], $this->managerHeaders)->assertStatus(409)->assertJsonPath('errors.0.code', 'PO_NOT_EDITABLE');

        // Pengaju & gudang tidak berwenang menyetujui.
        $this->postJson("/api/v1/purchase-orders/{$id}/approve", [], $this->managerHeaders)->assertForbidden();
        $this->postJson("/api/v1/purchase-orders/{$id}/approve", [], $this->warehouseHeaders)->assertForbidden();
        $this->postJson("/api/v1/purchase-orders/{$id}/approve", ['note' => 'OK, harga sesuai kontrak'], $this->headers)->assertOk()->assertJsonPath('data.status', 'approved');

        // Terima 1 karton susu dengan harga faktur berbeda.
        $first = $this->postJson("/api/v1/purchase-orders/{$id}/receipts", [
            'supplier_invoice_no' => 'INV/SSL/0915',
            'lines' => [['purchase_order_line_id' => $milkLine, 'qty' => '1', 'unit_price' => '228000'], ['purchase_order_line_id' => $beanLine, 'qty' => '0']],
        ], $this->warehouseHeaders)->assertCreated();
        $first->assertJsonPath('data.total', '228000.00')
            ->assertJsonPath('data.lines.0.base_qty', '12000.0000')
            ->assertJsonPath('data.lines.0.base_unit_cost', '19.000000')
            ->assertJsonCount(1, 'data.lines');
        $this->getJson("/api/v1/purchase-orders/{$id}", $this->managerHeaders)->assertOk()
            ->assertJsonPath('data.status', 'partially_received')
            ->assertJsonPath('data.lines.0.received_qty', '1.0000')
            ->assertJsonCount(1, 'data.receipts');

        // HPP rata-rata: (6000 × 16 + 12000 × 19) / 18000 = 18.
        expect(Stock::balance($this->pos->company, $this->main, $this->milk))->toBe(['qty' => '18000.0000', 'avg_cost' => '18.000000']);
        expect($this->pos->tenant(fn () => Ingredient::query()->find($this->milk->id)->last_cost))->toBe('19.000000');
        $log = $this->pos->tenant(fn () => AuditLog::query()->where('action', 'goods_receipt.created')->sole());
        expect($log->new_values['price_changed'])->toBeTrue();

        $this->postJson("/api/v1/purchase-orders/{$id}/receipts", ['lines' => [['purchase_order_line_id' => $milkLine, 'qty' => '2']]], $this->warehouseHeaders)
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'QTY_EXCEEDS_ORDER');

        // Manajer outlet (inventory.manage) boleh menerima barang di outletnya.
        $this->postJson("/api/v1/purchase-orders/{$id}/receipts", ['lines' => [
            ['purchase_order_line_id' => $milkLine, 'qty' => '1'], ['purchase_order_line_id' => $beanLine, 'qty' => '1.5'],
        ]], $this->managerHeaders)->assertCreated()->assertJsonPath('data.total', '612000.00');
        $this->getJson("/api/v1/purchase-orders/{$id}", $this->headers)->assertOk()->assertJsonPath('data.status', 'received');
        expect(Stock::balance($this->pos->company, $this->main, $this->beans))->toBe(['qty' => '1500.0000', 'avg_cost' => '260.000000']);

        $this->postJson("/api/v1/purchase-orders/{$id}/cancel", ['reason' => 'Batal'], $this->headers)->assertStatus(409);
        $this->getJson('/api/v1/goods-receipts', $this->headers)->assertOk()->assertJsonPath('meta.pagination.total', 2);
        // Saldo awal + 2 penerimaan PO.
        $this->getJson('/api/v1/stock/movements?type=receipt&ingredient_id='.$this->milk->id, $this->headers)->assertOk()->assertJsonPath('meta.pagination.total', 3);

        // Penerimaan & PO final tidak dapat diubah langsung di database.
        expect(fn () => $this->pos->tenant(fn () => DB::table('goods_receipts')->update(['total' => 1])))->toThrow(QueryException::class);
        expect(fn () => $this->pos->tenant(fn () => PurchaseOrder::query()->whereKey($id)->update(['notes' => 'ubah'])))->toThrow(QueryException::class);
    });

    it('PO ditolak, dibatalkan, dan ditutup setelah diterima sebagian', function () {
        $lines = [['ingredient_id' => $this->milk->id, 'unit_name' => 'kotak', 'qty' => '24', 'unit_price' => '18500']];
        $rejected = $this->postJson('/api/v1/purchase-orders', poBody($this->main->id, $this->supplierId, $lines), $this->warehouseHeaders)->json('data.id');
        $this->postJson("/api/v1/purchase-orders/{$rejected}/submit", [], $this->warehouseHeaders)->assertOk();
        $this->postJson("/api/v1/purchase-orders/{$rejected}/reject", [], $this->headers)->assertUnprocessable();
        $this->postJson("/api/v1/purchase-orders/{$rejected}/reject", ['note' => 'Harga terlalu tinggi'], $this->headers)->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_note', 'Harga terlalu tinggi');

        // Manajer outlet hanya dapat membatalkan PO buatannya sendiri.
        $mine = $this->postJson('/api/v1/purchase-orders', poBody($this->main->id, $this->supplierId, $lines), $this->warehouseHeaders)->json('data.id');
        $this->postJson("/api/v1/purchase-orders/{$mine}/cancel", ['reason' => 'Tidak perlu'], $this->managerHeaders)->assertForbidden();
        $this->postJson("/api/v1/purchase-orders/{$mine}/cancel", ['reason' => 'Stok masih cukup'], $this->warehouseHeaders)->assertOk()->assertJsonPath('data.status', 'cancelled');

        $partial = $this->postJson('/api/v1/purchase-orders', poBody($this->main->id, $this->supplierId, $lines), $this->warehouseHeaders);
        $pid = $partial->json('data.id');
        $this->postJson("/api/v1/purchase-orders/{$pid}/submit", [], $this->warehouseHeaders);
        $this->postJson("/api/v1/purchase-orders/{$pid}/approve", [], $this->headers)->assertOk();
        $this->postJson("/api/v1/purchase-orders/{$pid}/close", ['reason' => 'Sisa tidak tersedia'], $this->warehouseHeaders)->assertStatus(409);
        $this->postJson("/api/v1/purchase-orders/{$pid}/receipts", ['lines' => [['purchase_order_line_id' => $partial->json('data.lines.0.id'), 'qty' => '12']]], $this->warehouseHeaders)->assertCreated();
        $this->postJson("/api/v1/purchase-orders/{$pid}/close", ['reason' => 'Sisa tidak tersedia di pemasok'], $this->warehouseHeaders)->assertOk()->assertJsonPath('data.status', 'closed');

        $this->getJson('/api/v1/purchase-orders?status=closed', $this->headers)->assertOk()->assertJsonPath('meta.pagination.total', 1);
        // Pemasok dengan PO berjalan tidak dapat dihapus; setelah semua final boleh.
        $this->deleteJson("/api/v1/suppliers/{$this->supplierId}", [], $this->warehouseHeaders)->assertOk();
    });

    it('memvalidasi PO: satuan, bahan, dan pemasok', function () {
        $this->postJson('/api/v1/purchase-orders', poBody($this->main->id, $this->supplierId, [
            ['ingredient_id' => $this->milk->id, 'unit_name' => 'galon', 'qty' => '1', 'unit_price' => '1000'],
        ]), $this->headers)->assertUnprocessable()->assertJsonPath('errors.0.code', 'UNIT_UNKNOWN')->assertJsonPath('errors.0.field', 'lines.0.unit_name');

        $inactive = $this->postJson('/api/v1/suppliers', ['code' => 'SUP-OFF', 'name' => 'UD Lama', 'is_active' => false], $this->headers)->json('data.id');
        $this->postJson('/api/v1/purchase-orders', poBody($this->main->id, $inactive, [
            ['ingredient_id' => $this->milk->id, 'unit_name' => 'ml', 'qty' => '1', 'unit_price' => '20'],
        ]), $this->headers)->assertUnprocessable()->assertJsonPath('errors.0.code', 'SUPPLIER_UNKNOWN');

        $bad = $this->postJson('/api/v1/purchase-orders', ['location_id' => $this->main->id, 'supplier_id' => $this->supplierId, 'order_date' => now()->addDay()->format('Y-m-d'), 'lines' => [
            ['ingredient_id' => $this->milk->id, 'unit_name' => 'ml', 'qty' => '0', 'unit_price' => '-1'],
        ]], $this->headers)->assertUnprocessable();
        expect(errorFields($bad))->toContain('order_date', 'lines.0.qty', 'lines.0.unit_price');
    });

    it('membatasi PO sesuai cakupan outlet & tenant', function () {
        $po = $this->postJson('/api/v1/purchase-orders', poBody($this->main->id, $this->supplierId, [
            ['ingredient_id' => $this->milk->id, 'unit_name' => 'karton', 'qty' => '1', 'unit_price' => '222000'],
        ]), $this->managerHeaders)->assertCreated()->json('data.id');

        [$dagoManager] = Factory::staff($this->pos->company, ['outlet_manager'], [$this->pos->otherOutlet->id]);
        $h = asMember($dagoManager, $this->pos->company);
        $this->getJson("/api/v1/purchase-orders/{$po}", $h)->assertNotFound();
        $this->getJson('/api/v1/purchase-orders', $h)->assertOk()->assertJsonPath('meta.pagination.total', 0);
        $this->postJson('/api/v1/purchase-orders', poBody($this->main->id, $this->supplierId, [
            ['ingredient_id' => $this->milk->id, 'unit_name' => 'ml', 'qty' => '1', 'unit_price' => '20'],
        ]), $h)->assertForbidden();

        [$finance] = Factory::staff($this->pos->company, ['finance']);
        $this->getJson("/api/v1/purchase-orders/{$po}", asMember($finance, $this->pos->company))->assertOk();
        $this->postJson("/api/v1/purchase-orders/{$po}/submit", [], asMember($finance, $this->pos->company))->assertForbidden();
        $this->getJson('/api/v1/purchase-orders', asMember($this->pos->staff['kitchen']['user'], $this->pos->company))->assertForbidden();

        [$other, $otherOwner] = Factory::company('Warung Bu Ratna');
        $oh = asMember($otherOwner, $other);
        $this->getJson("/api/v1/purchase-orders/{$po}", $oh)->assertNotFound();
        $this->postJson("/api/v1/purchase-orders/{$po}/approve", [], $oh)->assertNotFound();
        $this->postJson("/api/v1/purchase-orders/{$po}/receipts", ['lines' => []], $oh)->assertNotFound();
        $this->postJson('/api/v1/purchase-orders', poBody($this->main->id, $this->supplierId, [
            ['ingredient_id' => $this->milk->id, 'unit_name' => 'ml', 'qty' => '1', 'unit_price' => '20'],
        ]), $oh)->assertUnprocessable()->assertJsonPath('errors.0.code', 'LOCATION_UNKNOWN');
    });
});

describe('penerimaan tanpa PO & food cost (FR-INV-05, FR-INV-10)', function () {
    it('penerimaan tanpa PO memakai konversi satuan dan dipakai sebagai dasar food cost', function () {
        $receipt = $this->postJson('/api/v1/goods-receipts', [
            'location_id' => $this->main->id, 'supplier_id' => $this->supplierId, 'supplier_invoice_no' => 'NOTA-778',
            'lines' => [
                ['ingredient_id' => $this->beans->id, 'unit_name' => 'kg', 'qty' => '2', 'unit_price' => '250000'],
                ['ingredient_id' => $this->milk->id, 'unit_name' => 'karton', 'qty' => '1', 'unit_price' => '216000'],
            ],
        ], $this->managerHeaders)->assertCreated();
        $receipt->assertJsonPath('data.total', '716000.00')->assertJsonPath('data.purchase_order_id', null);
        $this->getJson('/api/v1/goods-receipts/'.$receipt->json('data.id'), $this->headers)->assertOk()->assertJsonCount(2, 'data.lines');

        Stock::recipe($this->pos->company, Recipe::ITEM, $this->pos->coffee->id, [[$this->beans, '18'], [$this->milk, '150']]);
        $menu = $this->getJson('/api/v1/reports/food-cost/menu?outlet_id='.$this->pos->outlet->id, $this->headers)->assertOk();
        $rows = collect($menu->json('data'));
        $regular = $rows->firstWhere('name', 'Kopi Susu · Regular');
        // 18 g × 250 + 150 ml × 18 = 7.200 dari harga 18.000 (belum termasuk pajak) = 40%.
        expect($regular)->toMatchArray(['price' => '18000.00', 'net_price' => '18000.00', 'cost' => '7200.00', 'food_cost_percent' => '40.0', 'gross_margin' => '10800.00', 'has_recipe' => true, 'missing_cost' => false]);
        expect($rows->firstWhere('name', 'Croissant'))->toMatchArray(['has_recipe' => false, 'cost' => null]);

        // Penjualan & waste → food cost aktual.
        [$shiftId, $ymd] = $this->pos->openShift();
        $this->pos->tenant(fn () => DB::table('outlets')->where('id', $this->pos->outlet->id)->update(['stock_deduction_trigger' => 'on_payment']));
        $this->pos->push('order', $this->pos->order($shiftId, $this->pos->receipt($ymd, 1)));
        $this->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->main->id, 'type' => 'waste', 'reason_code' => 'expired',
            'lines' => [['ingredient_id' => $this->milk->id, 'qty' => '500']],
        ], $this->headers)->assertCreated();

        $actual = $this->getJson('/api/v1/reports/food-cost?outlet_id='.$this->pos->outlet->id, $this->headers)->assertOk();
        // Penjualan bersih 78.500 − 7.140 pajak − 3.400 service + 40 pembulatan = 68.000.
        $actual->assertJsonPath('data.outlets.0.net_sales', '68000.00')
            ->assertJsonPath('data.outlets.0.theoretical_cost', '7200.00')
            ->assertJsonPath('data.outlets.0.waste_cost', '9000.00')
            ->assertJsonPath('data.outlets.0.actual_cost', '16200.00')
            ->assertJsonPath('data.outlets.0.theoretical_percent', '10.6')
            ->assertJsonPath('data.outlets.0.actual_percent', '23.8')
            ->assertJsonPath('data.total.actual_cost', '16200.00')
            ->assertJsonPath('data.top_ingredients.0.name', 'Susu UHT Full Cream')
            ->assertJsonPath('data.top_ingredients.0.qty', '650.0000');

        // Kasir tidak melihat laporan; manajer outlet lain tidak melihat outlet ini.
        $this->getJson('/api/v1/reports/food-cost', asMember($this->pos->staff['cashier']['user'], $this->pos->company))->assertForbidden();
        [$dagoManager] = Factory::staff($this->pos->company, ['outlet_manager'], [$this->pos->otherOutlet->id]);
        $this->getJson('/api/v1/reports/food-cost/menu?outlet_id='.$this->pos->outlet->id, asMember($dagoManager, $this->pos->company))->assertNotFound();
        $this->getJson('/api/v1/reports/food-cost?outlet_id='.$this->pos->outlet->id, asMember($dagoManager, $this->pos->company))->assertNotFound();
        [$other, $otherOwner] = Factory::company('Warung Bu Ratna');
        $this->getJson('/api/v1/reports/food-cost/menu?outlet_id='.$this->pos->outlet->id, asMember($otherOwner, $other))->assertNotFound();
    });
});
