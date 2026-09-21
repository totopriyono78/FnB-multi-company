<?php

use App\Filament\Pages\MenuAvailability;
use App\Filament\Pages\PriceSimulator;
use App\Filament\Resources\ItemResource\Pages\CreateItem;
use App\Filament\Resources\ItemResource\Pages\EditItem;
use App\Filament\Resources\ItemResource\Pages\ListItems;
use App\Filament\Resources\ItemResource\RelationManagers\PricesRelationManager;
use App\Filament\Resources\MenuCategoryResource\Pages\CreateMenuCategory;
use App\Filament\Resources\MenuCategoryResource\Pages\ListMenuCategories;
use App\Filament\Resources\ModifierGroupResource\Pages\CreateModifierGroup;
use App\Filament\Resources\ModifierGroupResource\Pages\EditModifierGroup;
use App\Filament\Resources\PromotionResource\Pages\CreatePromotion;
use App\Filament\Resources\PromotionResource\Pages\EditPromotion;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemPrice;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use App\Modules\Catalog\Domain\Models\OutletItemAvailability;
use App\Modules\Catalog\Domain\Models\Promotion;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Support\Factory;
use Tests\Support\Menu;

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    [$this->other] = Factory::company('Warung Bu Ratna');
    $this->brand = Factory::brand($this->company, ['code' => 'KTJ', 'name' => 'Kopi Tepi Jalan']);
    $this->otherBrand = Factory::brand($this->company, ['code' => 'RB88', 'name' => 'Roti Bakar 88']);
    $this->kemang = Factory::outlet($this->company, $this->brand, ['code' => 'KMG', 'name' => 'Kemang']);
});

it('membuka semua halaman menu untuk pemilik', function (string $path) {
    Menu::item($this->company, $this->brand, ['name' => 'Kopi Susu']);

    $this->actingAs($this->owner)->get("/admin/{$this->company->code}{$path}")->assertOk();
})->with([
    'daftar menu' => ['/menu'],
    'tambah menu' => ['/menu/create'],
    'kategori' => ['/kategori-menu'],
    'modifier' => ['/modifier'],
    'promo' => ['/promo'],
    'tambah promo' => ['/promo/create'],
    'ketersediaan' => ['/ketersediaan-menu'],
    'simulasi' => ['/simulasi-harga'],
    'stasiun' => ['/stasiun-dapur'],
    'channel' => ['/channel-penjualan'],
]);

it('membuka halaman ubah menu dan menolak menu company lain', function () {
    $item = Menu::item($this->company, $this->brand);
    $foreign = Menu::item($this->other, Factory::brand($this->other));

    $this->actingAs($this->owner)->get("/admin/{$this->company->code}/menu/{$item->id}/edit")->assertOk();
    $this->actingAs($this->owner)->get("/admin/{$this->company->code}/menu/{$foreign->id}/edit")->assertNotFound();
});

it('menyembunyikan halaman menu dari kasir', function () {
    [$cashier] = Factory::staff($this->company, ['cashier'], [$this->kemang->id]);

    $this->actingAs($cashier)->get("/admin/{$this->company->code}/menu")->assertForbidden();
    $this->actingAs($cashier)->get("/admin/{$this->company->code}/promo")->assertForbidden();
    $this->actingAs($cashier)->get("/admin/{$this->company->code}/simulasi-harga")->assertForbidden();
    $this->actingAs($cashier)->get("/admin/{$this->company->code}/stasiun-dapur")->assertForbidden();
    // Kasir boleh menandai menu habis (FR-MENU-08).
    $this->actingAs($cashier)->get("/admin/{$this->company->code}/ketersediaan-menu")->assertOk();
});

describe('komponen Livewire', function () {
    beforeEach(function () {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
        app(TenantContext::class)->setTenant($this->company->id);
    });

    afterEach(fn () => app(TenantContext::class)->reset());

    it('membuat kategori dan menolak nama ganda dalam brand', function () {
        Livewire::test(CreateMenuCategory::class)
            ->fillForm(['brand_id' => $this->brand->id, 'name' => 'Kopi', 'color' => 'amber'])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateMenuCategory::class)
            ->fillForm(['brand_id' => $this->brand->id, 'name' => 'KOPI', 'color' => 'amber'])
            ->call('create')
            ->assertHasFormErrors(['name']);

        $foreign = Factory::brand($this->other);
        Livewire::test(CreateMenuCategory::class)
            ->fillForm(['brand_id' => $foreign->id, 'name' => 'Curang', 'color' => 'gray'])
            ->call('create')
            ->assertHasFormErrors(['brand_id']);

        expect(MenuCategory::query()->count())->toBe(1);
        Livewire::test(ListMenuCategories::class)->assertCanSeeTableRecords(MenuCategory::query()->get());
    });

    it('membuat menu dengan varian dan modifier dari form', function () {
        $category = Menu::category($this->company, $this->brand, 'Kopi');
        $sugar = Menu::modifierGroup($this->company, $this->brand, [['name' => 'Normal', 'price' => '0']], 1, 1);
        app(TenantContext::class)->setTenant($this->company->id);

        Livewire::test(CreateItem::class)
            ->fillForm([
                'brand_id' => $this->brand->id,
                'category_id' => $category->id,
                'type' => 'single',
                'sku' => 'KSTJ-01',
                'name' => 'Kopi Susu Tepi Jalan',
                'base_price' => '18000',
                'variants' => [
                    ['name' => 'Regular', 'price' => '18000', 'is_default' => true],
                    ['name' => 'Large', 'price' => '24000', 'is_default' => false],
                ],
                'modifier_group_ids' => [$sugar->id],
                'channel_codes' => ['dine_in', 'take_away'],
                'schedule' => [['days' => ['1', '2'], 'start' => '07:00', 'end' => '11:00']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = Item::query()->with(['variants', 'modifierGroups'])->where('sku', 'KSTJ-01')->firstOrFail();
        expect((string) $item->base_price)->toBe('18000.00')
            ->and($item->short_name)->toBe('Kopi Susu Tepi Jalan')
            ->and($item->variants->pluck('name')->all())->toBe(['Regular', 'Large'])
            ->and($item->modifierGroups->pluck('id')->all())->toBe([$sugar->id])
            ->and($item->channel_codes)->toBe(['dine_in', 'take_away'])
            ->and($item->schedule)->toEqual([['days' => [1, 2], 'start' => '07:00', 'end' => '11:00']]); // jsonb tidak menjaga urutan kunci

        // SKU ganda (beda huruf) ditolak.
        Livewire::test(CreateItem::class)
            ->fillForm(['brand_id' => $this->brand->id, 'category_id' => $category->id, 'type' => 'single', 'sku' => 'kstj-01', 'name' => 'Ganda', 'base_price' => '1'])
            ->call('create')
            ->assertHasFormErrors(['sku']);

        // Ubah: form terisi dari data tersimpan lalu simpan harga baru.
        Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
            ->assertFormSet(['sku' => 'KSTJ-01', 'base_price' => '18000', 'modifier_group_ids' => [$sugar->id]])
            ->fillForm(['base_price' => '19000'])
            ->call('save')
            ->assertHasNoFormErrors();
        expect((string) $item->refresh()->base_price)->toBe('19000.00')
            ->and($item->variants()->count())->toBe(2);
    });

    it('menolak nilai di luar pilihan pada form menu', function () {
        $category = Menu::category($this->company, $this->brand, 'Kopi');
        app(TenantContext::class)->setTenant($this->company->id);

        Livewire::test(CreateItem::class)
            ->fillForm([
                'brand_id' => $this->brand->id, 'category_id' => $category->id, 'type' => 'combo',
                'sku' => 'X-9', 'name' => 'Curang', 'base_price' => '1000',
                'channel_codes' => ['tokopedia'],
            ])
            ->call('create')
            ->assertHasFormErrors(['type', 'channel_codes.0']);
        expect(Item::query()->count())->toBe(0);
    });

    it('menolak brand yang tidak dikelola saat membuat menu', function () {
        [$brandManager] = Factory::staff($this->company, ['brand_manager'], [], null, [$this->brand->id]);
        $category = Menu::category($this->company, $this->otherBrand);
        $this->actingAs($brandManager);
        app(TenantContext::class)->setTenant($this->company->id);

        Livewire::test(CreateItem::class)
            ->fillForm(['brand_id' => $this->otherBrand->id, 'category_id' => $category->id, 'type' => 'single', 'sku' => 'X-1', 'name' => 'Curang', 'base_price' => '1000'])
            ->call('create')
            ->assertHasFormErrors(['brand_id']);
        expect(Item::query()->count())->toBe(0);

        $mine = Menu::item($this->company, $this->brand);
        $theirs = Menu::item($this->company, $this->otherBrand);
        app(TenantContext::class)->setTenant($this->company->id);
        Livewire::test(ListItems::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    });

    it('membuat grup modifier dan mengubah pilihannya', function () {
        Livewire::test(CreateModifierGroup::class)
            ->fillForm([
                'brand_id' => $this->brand->id, 'name' => 'Topping', 'min_select' => 0, 'max_select' => 2, 'is_active' => true,
                'modifiers' => [['name' => 'Boba', 'price' => '5000'], ['name' => 'Keju', 'price' => '6000']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $group = ModifierGroup::query()->with('modifiers')->firstOrFail();
        expect($group->modifiers->pluck('price', 'name')->map(fn ($p) => (string) $p)->all())->toBe(['Boba' => '5000.00', 'Keju' => '6000.00']);

        Livewire::test(EditModifierGroup::class, ['record' => $group->getRouteKey()])
            ->fillForm(['max_select' => 0])
            ->call('save')
            ->assertHasFormErrors(['max_select']);
    });

    it('mengelola harga khusus lewat relation manager', function () {
        $item = Menu::item($this->company, $this->brand, ['base_price' => '18000']);
        $foreignOutlet = Factory::outlet($this->company, $this->otherBrand);
        app(TenantContext::class)->setTenant($this->company->id);

        $rm = Livewire::test(PricesRelationManager::class, ['ownerRecord' => $item, 'pageClass' => EditItem::class]);
        $rm->callTableAction('create', data: ['outlet_id' => $this->kemang->id, 'price' => '20000'])->assertHasNoTableActionErrors();
        $rm->callTableAction('create', data: ['outlet_id' => $this->kemang->id, 'price' => '21000'])->assertHasTableActionErrors(['price']);
        $rm->callTableAction('create', data: ['outlet_id' => $foreignOutlet->id, 'price' => '1'])->assertHasTableActionErrors(['outlet_id']);
        $rm->callTableAction('create', data: ['price' => '1'])->assertHasTableActionErrors(['outlet_id']);

        expect(ItemPrice::query()->where('item_id', $item->id)->pluck('price')->map(fn ($p) => (string) $p)->all())->toBe(['20000.00']);
    });

    it('membuat promo happy hour dan memvalidasi aturan', function () {
        $category = Menu::category($this->company, $this->brand, 'Kopi');
        app(TenantContext::class)->setTenant($this->company->id);

        Livewire::test(CreatePromotion::class)
            ->fillForm([
                'name' => 'Happy Hour', 'brand_id' => $this->brand->id, 'type' => 'percent', 'scope' => 'items', 'value' => '20',
                'category_ids' => [$category->id], 'outlet_ids' => [$this->kemang->id],
                'days_of_week' => ['1', '2', '3'], 'time_start' => '14:00', 'time_end' => '17:00',
                'starts_at' => '2026-09-16 00:00:00', 'auto_apply' => true, 'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $promo = Promotion::query()->with(['targets', 'outlets'])->firstOrFail();
        expect($promo->days_of_week)->toBe([1, 2, 3])
            ->and($promo->time_start)->toStartWith('14:00')
            ->and($promo->targets->pluck('target_id')->all())->toBe([$category->id])
            ->and($promo->outlets->pluck('id')->all())->toBe([$this->kemang->id]);

        // Persen > 100 ditolak; promo per menu tanpa target ditolak.
        Livewire::test(EditPromotion::class, ['record' => $promo->getRouteKey()])
            ->fillForm(['value' => '150'])
            ->call('save')
            ->assertHasFormErrors(['value']);
        expect((string) $promo->refresh()->value)->toBe('20.00');
        Livewire::test(CreatePromotion::class)
            ->fillForm(['name' => 'Tanpa Target', 'brand_id' => $this->brand->id, 'type' => 'amount', 'scope' => 'items', 'value' => '5000', 'starts_at' => '2026-09-16 00:00:00', 'auto_apply' => true])
            ->call('create')
            ->assertHasFormErrors(['item_ids']);
        // Kode wajib bila tidak otomatis.
        Livewire::test(CreatePromotion::class)
            ->fillForm(['name' => 'Kode', 'brand_id' => $this->brand->id, 'type' => 'amount', 'scope' => 'order', 'value' => '5000', 'starts_at' => '2026-09-16 00:00:00', 'auto_apply' => false])
            ->call('create')
            ->assertHasFormErrors(['code']);
    });

    it('menandai menu habis dan menyembunyikan menu per outlet', function () {
        $item = Menu::item($this->company, $this->brand, ['name' => 'Croissant']);
        app(TenantContext::class)->setTenant($this->company->id);

        Livewire::withQueryParams(['outlet' => $this->kemang->id])
            ->test(MenuAvailability::class)
            ->assertCanSeeTableRecords([$item])
            ->call('updateTableColumnState', 'avail_sold_out', $item->getKey(), true)
            ->call('updateTableColumnState', 'avail_listed', $item->getKey(), false);

        $row = OutletItemAvailability::query()->where('item_id', $item->id)->where('outlet_id', $this->kemang->id)->firstOrFail();
        expect($row->is_sold_out)->toBeTrue()->and($row->is_listed)->toBeFalse();
    });

    it('kasir hanya boleh menandai habis, tidak menyembunyikan menu', function () {
        $item = Menu::item($this->company, $this->brand);
        [$cashier] = Factory::staff($this->company, ['cashier'], [$this->kemang->id]);
        $dago = Factory::outlet($this->company, $this->brand, ['code' => 'DGO']);
        $this->actingAs($cashier);
        app(TenantContext::class)->setTenant($this->company->id);

        Livewire::withQueryParams(['outlet' => $this->kemang->id])
            ->test(MenuAvailability::class)
            ->call('updateTableColumnState', 'avail_listed', $item->getKey(), false)
            ->call('updateTableColumnState', 'avail_sold_out', $item->getKey(), true);

        $row = OutletItemAvailability::query()->where('item_id', $item->id)->firstOrFail();
        expect($row->is_listed)->toBeTrue()->and($row->is_sold_out)->toBeTrue();

        // Outlet di luar cakupan diabaikan: halaman kembali ke outlet milik kasir.
        Livewire::withQueryParams(['outlet' => $dago->id])
            ->test(MenuAvailability::class)
            ->assertSet('outletId', $this->kemang->id);
    });

    it('menghitung simulasi harga sesuai pengaturan outlet', function () {
        Outlet::query()->whereKey($this->kemang->id)->update(['tax_rate' => '10', 'service_charge_rate' => '5', 'rounding_unit' => 100]);
        $item = Menu::item($this->company, $this->brand, ['name' => 'Nasi Goreng', 'base_price' => '32000']);
        app(TenantContext::class)->setTenant($this->company->id);

        // 32.000 × 2 = 64.000; SC 3.200; pajak 10% × 67.200 = 6.720; total 73.920 → 73.900
        Livewire::test(PriceSimulator::class)
            ->fillForm([
                'outlet_id' => $this->kemang->id,
                'channel_code' => 'dine_in',
                'lines' => [['item_id' => $item->id, 'qty' => 2]],
            ])
            ->call('calculate')
            ->assertHasNoFormErrors()
            ->assertSet('result.totals.total', '73900.00')
            ->assertSee('Rp73.900')
            ->assertSee('Rp3.200');

        // Menu habis → pesan kesalahan, tanpa hasil.
        OutletItemAvailability::query()->create(['item_id' => $item->id, 'outlet_id' => $this->kemang->id, 'is_sold_out' => true, 'is_listed' => true]);
        Livewire::test(PriceSimulator::class)
            ->fillForm(['outlet_id' => $this->kemang->id, 'channel_code' => 'dine_in', 'lines' => [['item_id' => $item->id, 'qty' => 1]]])
            ->call('calculate')
            ->assertSet('result', null)
            ->assertSee('sedang habis');
    });

    it('mengimpor menu dari header aksi dengan mode periksa', function () {
        $csv = UploadedFile::fake()->createWithContent('menu.csv', "kategori,sku,nama,harga\nKopi,KS-1,Kopi Susu,18000\n");

        Livewire::test(ListItems::class)
            ->callAction('import', data: ['brand_id' => $this->brand->id, 'file' => $csv, 'dry_run' => true])
            ->assertHasNoActionErrors()
            ->assertNotified('File valid. Belum ada yang disimpan.');
        expect(Item::query()->count())->toBe(0);

        Livewire::test(ListItems::class)
            ->callAction('import', data: ['brand_id' => $this->brand->id, 'file' => $csv, 'dry_run' => false])
            ->assertNotified('Impor selesai');
        expect(Item::query()->where('sku', 'KS-1')->exists())->toBeTrue();
    });
});
