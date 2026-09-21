<?php

use App\Filament\Pages\RecipeEditor;
use App\Filament\Resources\GoodsReceiptResource\Pages\CreateGoodsReceipt;
use App\Filament\Resources\IngredientResource\Pages\CreateIngredient;
use App\Filament\Resources\PurchaseOrderResource\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrderResource\Pages\ViewPurchaseOrder;
use App\Filament\Resources\StockAdjustmentResource\Pages\CreateStockAdjustment;
use App\Filament\Resources\StockBalanceResource\Pages\ListStockBalances;
use App\Filament\Resources\StockCountResource\Pages\CreateStockCount;
use App\Filament\Resources\StockCountResource\Pages\ViewStockCount;
use App\Filament\Resources\StockTransferResource\Pages\CreateStockTransfer;
use App\Filament\Resources\StockTransferResource\Pages\ViewStockTransfer;
use App\Filament\Widgets\OperationalAlerts;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Application\StockDocumentService;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\Recipe;
use App\Modules\Inventory\Domain\Models\StockAdjustment;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Domain\Models\StockCount;
use App\Modules\Inventory\Domain\Models\StockTransfer;
use App\Modules\Purchasing\Domain\Models\GoodsReceipt;
use App\Modules\Purchasing\Domain\Models\PurchaseOrder;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Tenancy\Application\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Factory;
use Tests\Support\Pos;
use Tests\Support\Stock;

beforeEach(function () {
    $this->pos = Pos::setup();
    $company = $this->pos->company;
    $this->owner = Factory::ownerOf($company);
    $this->main = Stock::location($company, $this->pos->outlet);
    $this->dago = Stock::location($company, $this->pos->otherOutlet);
    $this->milk = Stock::ingredient($company, 'Susu Segar', 'ml', ['min_stock' => '2000'], ['karton' => '12000']);
    $this->beans = Stock::ingredient($company, 'Biji Kopi Arabika Gayo', 'g');
    Stock::receive($company, $this->main, $this->milk, '5000', '18');
    $this->supplier = Factory::tenant($company, fn () => Supplier::query()->create(['code' => 'SUP-01', 'name' => 'CV Sumber Susu Lembang']));
    $this->base = "/admin/{$company->code}";
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
});

function panelAs(User $user, Pos $pos): void
{
    test()->actingAs($user);
    Filament::setTenant($pos->company);
    app(TenantContext::class)->setTenant($pos->company->id);
}

it('membuka halaman inventory & pembelian untuk pemilik', function (string $path) {
    $this->actingAs($this->owner)->get($this->base.$path)->assertOk();
})->with([
    'bahan' => ['/bahan-baku'], 'bahan baru' => ['/bahan-baku/create'], 'resep' => ['/resep'],
    'stok' => ['/stok'], 'kartu stok' => ['/kartu-stok'], 'penyesuaian' => ['/penyesuaian-stok/create'],
    'transfer' => ['/transfer-stok/create'], 'opname' => ['/stock-opname/create'], 'food cost' => ['/food-cost'],
    'lokasi' => ['/lokasi-stok'], 'pemasok' => ['/pemasok'], 'po' => ['/purchase-order/create'],
    'penerimaan' => ['/penerimaan-barang/create'],
]);

// Satu request per kasus: beberapa request halaman Livewire dalam satu test membuat binding redirect Livewire tertinggal.
it('membatasi halaman inventory sesuai peran', function (string $who, string $path, int $status) {
    $user = $this->pos->staff[$who]['user'];
    $this->actingAs($user)->get($this->base.$path)->assertStatus($status);
})->with([
    'kasir: stok' => ['cashier', '/stok', 403],
    'kasir: purchase order' => ['cashier', '/purchase-order', 403],
    'kasir: food cost' => ['cashier', '/food-cost', 403],
    'dapur: stok' => ['kitchen', '/stok', 403],
    'dapur: penerimaan' => ['kitchen', '/penerimaan-barang', 403],
    'dapur: resep' => ['kitchen', '/resep', 403],
    'manajer outlet: stok' => ['manager', '/stok', 200],
    'manajer outlet: bahan baru' => ['manager', '/bahan-baku/create', 403],
    'manajer outlet: pemasok baru' => ['manager', '/pemasok/create', 403],
    'manajer outlet: buat PO' => ['manager', '/purchase-order/create', 200],
]);

it('dokumen outlet lain tidak terlihat oleh manajer outlet', function () {
    $doc = Factory::tenant($this->pos->company, fn () => app(StockDocumentService::class)->adjust($this->owner, [
        'location_id' => $this->dago->id, 'type' => 'waste', 'reason_code' => 'expired',
        'lines' => [['ingredient_id' => $this->milk->id, 'qty' => '1']],
    ]));
    $this->actingAs($this->pos->staff['manager']['user'])->get($this->base.'/penyesuaian-stok/'.$doc->id)->assertNotFound();
});

it('company lain tidak dapat membuka dokumen stok', function () {
    $doc = Factory::tenant($this->pos->company, fn () => app(StockDocumentService::class)->adjust($this->owner, [
        'location_id' => $this->main->id, 'type' => 'waste', 'reason_code' => 'expired',
        'lines' => [['ingredient_id' => $this->milk->id, 'qty' => '1']],
    ]));
    [$other, $otherOwner] = Factory::company('Warung Bu Ratna');
    $this->actingAs($otherOwner)->get("/admin/{$other->code}/penyesuaian-stok/{$doc->id}")->assertNotFound();
});

it('menambah bahan beserta satuan beli dari back-office', function () {
    panelAs($this->owner, $this->pos);
    Livewire::test(CreateIngredient::class)
        ->fillForm(['code' => 'GULA-AREN', 'name' => 'Gula Aren Cair', 'category' => 'Pemanis', 'base_unit' => 'ml', 'kind' => 'raw', 'min_stock' => '1000'])
        ->set('data.units', [['name' => 'jeriken', 'factor' => '5000', 'is_purchase_default' => true]])
        ->call('create')
        ->assertHasNoFormErrors();
    $ingredient = Ingredient::query()->with('units')->where('code', 'GULA-AREN')->sole();
    expect($ingredient->units->first()->name)->toBe('jeriken')->and($ingredient->units->first()->factor)->toBe('5000.0000');

    Livewire::test(CreateIngredient::class)
        ->fillForm(['code' => 'gula-aren', 'name' => 'Duplikat', 'base_unit' => 'ml', 'kind' => 'raw', 'min_stock' => '0'])
        ->call('create')
        ->assertHasFormErrors(['code']);
});

it('menyusun resep dan menampilkan HPP per porsi', function () {
    panelAs($this->owner, $this->pos);
    Livewire::test(RecipeEditor::class, ['type' => 'item', 'target' => $this->pos->coffee->id, 'outletId' => $this->pos->outlet->id])
        ->set('data.lines', [['ingredient_id' => $this->milk->id, 'qty' => '150'], ['ingredient_id' => $this->beans->id, 'qty' => '18']])
        ->callAction('save')
        ->assertNotified('Resep disimpan.')
        ->assertSee('HPP teoritis per porsi')
        ->assertSee('Sebagian bahan belum punya harga')
        ->assertSee('Rp2.700');

    expect(Recipe::query()->where('target_id', $this->pos->coffee->id)->sole()->lines()->count())->toBe(2);

    // Manajer outlet hanya melihat.
    panelAs($this->pos->staff['manager']['user'], $this->pos);
    Livewire::test(RecipeEditor::class, ['type' => 'item', 'target' => $this->pos->coffee->id])
        ->assertActionHidden('save')
        ->assertSee('Anda hanya dapat melihat resep ini');
});

it('mencatat waste dan stok kritis tampil di dashboard', function () {
    panelAs($this->owner, $this->pos);
    Livewire::test(CreateStockAdjustment::class)
        ->fillForm(['location_id' => $this->main->id, 'type' => 'waste', 'reason_code' => 'expired'])
        ->set('data.lines', [['ingredient_id' => $this->milk->id, 'qty' => '3500', 'note' => 'Lewat tanggal']])
        ->call('create')
        ->assertHasNoFormErrors();
    $doc = StockAdjustment::query()->sole();
    expect($doc->total_value)->toBe('-63000.00');
    $this->get($this->base.'/penyesuaian-stok/'.$doc->id)->assertOk()->assertSee($doc->number)->assertSee('Lewat tanggal');

    panelAs($this->owner, $this->pos);
    Livewire::test(ListStockBalances::class)
        ->filterTable('low', true)
        ->assertCanSeeTableRecords(StockBalance::query()->where('ingredient_id', $this->milk->id)->get())
        ->assertSee('Di bawah minimum');
    Livewire::test(OperationalAlerts::class)->assertSee('Stok kritis')->assertSee('Di bawah minimum atau minus');

    // Outlet yang melarang stok minus: waste melebihi saldo ditolak dengan pesan jelas.
    DB::table('outlets')->where('id', $this->pos->outlet->id)->update(['allow_negative_stock' => false]);
    Livewire::test(CreateStockAdjustment::class)
        ->fillForm(['location_id' => $this->main->id, 'type' => 'waste', 'reason_code' => 'spilled'])
        ->set('data.lines', [['ingredient_id' => $this->milk->id, 'qty' => '9999']])
        ->call('create')
        ->assertNotified('Stok Susu Segar tidak cukup di Gudang Utama. Outlet ini tidak mengizinkan stok minus.');
    expect(StockAdjustment::query()->count())->toBe(1);
});

it('mengirim dan menerima transfer dari back-office', function () {
    panelAs($this->owner, $this->pos);
    Livewire::test(CreateStockTransfer::class)
        ->fillForm(['from_location_id' => $this->main->id, 'to_location_id' => $this->dago->id])
        ->set('data.lines', [['ingredient_id' => $this->milk->id, 'qty' => '1000']])
        ->call('create')
        ->assertHasNoFormErrors();
    $transfer = StockTransfer::query()->with('lines')->sole();

    Livewire::test(ViewStockTransfer::class, ['record' => $transfer->id])
        ->mountAction('receive')
        ->setActionData(['note' => 'Satu kotak bocor'])
        ->set('mountedActionsData.0.lines', [['line_id' => $transfer->lines[0]->id, 'label' => 'x', 'qty_received' => '900']])
        ->callMountedAction()
        ->assertNotified('Transfer diterima. Stok tujuan sudah bertambah.');
    expect(Stock::balance($this->pos->company, $this->dago, $this->milk)['qty'])->toBe('900.0000')
        ->and($transfer->refresh()->status)->toBe('received');
});

it('opname: gudang menghitung tanpa melihat saldo, manajer menyetujui', function () {
    [$warehouse] = Factory::staff($this->pos->company, ['warehouse']);
    panelAs($warehouse, $this->pos);
    Livewire::test(CreateStockCount::class)
        ->fillForm(['location_id' => $this->main->id, 'scope' => 'partial', 'ingredient_ids' => [$this->milk->id]])
        ->call('create')
        ->assertHasNoFormErrors();
    $count = StockCount::query()->sole();

    $page = Livewire::test(ViewStockCount::class, ['record' => $count->id])
        ->assertActionVisible('record')
        ->assertActionHidden('approve')
        ->assertDontSee('5.000 ml');
    $page->mountAction('record')
        ->set('mountedActionsData.0.lines', [['ingredient_id' => $this->milk->id, 'label' => 'Susu', 'counted_qty' => '4800', 'note' => null]])
        ->callMountedAction()
        ->assertNotified('Hasil hitung disimpan.');
    Livewire::test(ViewStockCount::class, ['record' => $count->id])->callAction('submit')->assertNotified('Opname diajukan untuk disetujui.');

    panelAs($this->pos->staff['manager']['user'], $this->pos);
    Livewire::test(ViewStockCount::class, ['record' => $count->id])
        ->assertSee('5.000 ml')
        ->callAction('approve', ['note' => 'OK'])
        ->assertNotified('Opname disetujui. Stok sudah disesuaikan.');
    expect(Stock::balance($this->pos->company, $this->main, $this->milk)['qty'])->toBe('4800.0000');
});

it('alur PO di back-office: buat, ajukan, setujui, terima', function () {
    $manager = $this->pos->staff['manager']['user'];
    panelAs($manager, $this->pos);
    Livewire::test(CreatePurchaseOrder::class)
        ->fillForm(['location_id' => $this->main->id, 'supplier_id' => $this->supplier->id])
        ->set('data.lines', [['ingredient_id' => $this->milk->id, 'unit_name' => 'karton', 'qty' => '2', 'unit_price' => '216000']])
        ->call('create')
        ->assertHasNoFormErrors();
    $po = PurchaseOrder::query()->with('lines')->sole();
    expect($po->total)->toBe('432000.00');

    Livewire::test(ViewPurchaseOrder::class, ['record' => $po->id])
        ->assertActionHidden('approve')
        ->callAction('submit')
        ->assertNotified('PO diajukan.');

    panelAs($this->owner, $this->pos);
    Livewire::test(OperationalAlerts::class)->assertSee('PO menunggu persetujuan');
    Livewire::test(ViewPurchaseOrder::class, ['record' => $po->id])
        ->callAction('approve', ['note' => 'Harga sesuai'])
        ->assertNotified('PO disetujui.');

    panelAs($manager, $this->pos);
    Livewire::test(ViewPurchaseOrder::class, ['record' => $po->id])
        ->assertActionVisible('receive')
        ->mountAction('receive')
        // Form terisi sisa pesanan; pemasok baru mengirim 1 dari 2 karton.
        ->setActionData(['supplier_invoice_no' => 'SJ-0916'])
        ->set('mountedActionsData.0.lines', [['purchase_order_line_id' => $po->lines[0]->id, 'label' => 'x', 'qty' => '1', 'unit_price' => '216000']])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Barang diterima. Stok dan HPP sudah diperbarui.');
    expect($po->refresh()->status)->toBe('partially_received')
        ->and(Stock::balance($this->pos->company, $this->main, $this->milk)['qty'])->toBe('17000.0000');

    $this->get($this->base.'/purchase-order/'.$po->id)->assertOk()->assertSee('Diterima sebagian')->assertSee('CV Sumber Susu Lembang');
});

it('penerimaan tanpa PO dari back-office', function () {
    panelAs($this->owner, $this->pos);
    Livewire::test(CreateGoodsReceipt::class)
        ->fillForm(['location_id' => $this->main->id, 'supplier_invoice_no' => 'Nota pasar'])
        ->set('data.lines', [['ingredient_id' => $this->beans->id, 'unit_name' => 'g', 'qty' => '500', 'unit_price' => '260']])
        ->call('create')
        ->assertHasNoFormErrors();
    $receipt = GoodsReceipt::query()->sole();
    expect($receipt->total)->toBe('130000.00');
    $this->get($this->base.'/penerimaan-barang/'.$receipt->id)->assertOk()->assertSee('Tanpa PO')->assertSee('Nota pasar');
});
