<?php

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Catalog\Domain\Events\ItemAvailabilityChanged;
use App\Modules\Catalog\Domain\Models\ItemPriceHistory;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Tenancy\Domain\CompanyStatus;
use Illuminate\Support\Facades\Event;
use Tests\Support\Factory;
use Tests\Support\Menu;

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->brand = Factory::brand($this->company, ['code' => 'KTJ', 'name' => 'Kopi Tepi Jalan']);
    $this->otherBrand = Factory::brand($this->company, ['code' => 'RB8', 'name' => 'Roti Bakar 88']);
    $this->kemang = Factory::outlet($this->company, $this->brand, ['code' => 'KMG']);
    $this->dago = Factory::outlet($this->company, $this->brand, ['code' => 'DGO']);
    $this->headers = asMember($this->owner, $this->company);
});

it('menyiapkan channel penjualan dan stasiun dapur bawaan untuk company baru', function () {
    $channels = $this->getJson('/api/v1/sales-channels', $this->headers)->assertOk();
    expect(collect($channels->json('data'))->pluck('code')->all())
        ->toBe(['dine_in', 'take_away', 'delivery', 'gofood', 'grabfood', 'shopeefood', 'self_order']);
    expect(collect($channels->json('data'))->firstWhere('code', 'dine_in')['service_charge_applies'])->toBeTrue();

    $stations = $this->getJson('/api/v1/kitchen-stations', $this->headers)->assertOk();
    expect(collect($stations->json('data'))->pluck('code')->all())->toBe(['BAR', 'KITCHEN', 'PASTRY']);

    // Perintah provisioning aman diulang.
    $this->artisan('fnb:provision-catalog', ['company' => $this->company->id])->assertSuccessful();
    expect(Factory::tenant($this->company, fn () => SalesChannel::query()->count()))->toBe(7);
});

it('mengelola stasiun dapur dan channel penjualan', function () {
    $station = $this->postJson('/api/v1/kitchen-stations', ['code' => 'GRILL', 'name' => 'Panggangan'], $this->headers)
        ->assertCreated()->json('data');
    $this->postJson('/api/v1/kitchen-stations', ['code' => 'grill', 'name' => 'Ganda'], $this->headers)->assertUnprocessable();
    $this->patchJson("/api/v1/kitchen-stations/{$station['id']}", ['name' => 'Grill'], $this->headers)
        ->assertOk()->assertJsonPath('data.name', 'Grill');

    // Stasiun yang dipakai menu tidak bisa dihapus.
    Menu::item($this->company, $this->brand, ['kitchen_station_id' => $station['id']]);
    $this->deleteJson("/api/v1/kitchen-stations/{$station['id']}", [], $this->headers)->assertStatus(409);

    $channel = $this->postJson('/api/v1/sales-channels', ['code' => 'catering', 'name' => 'Katering', 'type' => 'in_store'], $this->headers)
        ->assertCreated()->json('data');
    expect($channel['is_system'])->toBeFalse();

    $dineIn = Menu::channel($this->company, 'dine_in');
    $this->patchJson("/api/v1/sales-channels/{$dineIn->id}", ['service_charge_applies' => false], $this->headers)
        ->assertOk()->assertJsonPath('data.service_charge_applies', false);
    // Kode channel bawaan tidak dapat diganti.
    $this->patchJson("/api/v1/sales-channels/{$dineIn->id}", ['code' => 'makan'], $this->headers)->assertUnprocessable();
});

it('mengelola kategori menu (FR-MENU-01)', function () {
    $created = $this->postJson('/api/v1/menu-categories', [
        'brand_id' => $this->brand->id, 'name' => 'Kopi Susu', 'color' => 'amber',
    ], $this->headers)->assertCreated();
    assertStandardEnvelope($created);
    $id = $created->json('data.id');

    $dup = $this->postJson('/api/v1/menu-categories', ['brand_id' => $this->brand->id, 'name' => 'kopi susu'], $this->headers)
        ->assertUnprocessable();
    expect(errorFields($dup))->toContain('name');
    // Nama sama boleh di brand lain.
    $this->postJson('/api/v1/menu-categories', ['brand_id' => $this->otherBrand->id, 'name' => 'Kopi Susu'], $this->headers)->assertCreated();

    $this->getJson('/api/v1/menu-categories?brand_id='.$this->brand->id, $this->headers)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Kopi Susu');

    $this->patchJson("/api/v1/menu-categories/{$id}", ['name' => 'Kopi Susu Gula Aren', 'brand_id' => $this->otherBrand->id], $this->headers)
        ->assertUnprocessable();
    $this->patchJson("/api/v1/menu-categories/{$id}", ['name' => 'Kopi Susu Gula Aren'], $this->headers)->assertOk();

    // Kategori berisi menu tidak dapat dihapus.
    Menu::item($this->company, $this->brand, ['category_id' => $id]);
    $this->deleteJson("/api/v1/menu-categories/{$id}", [], $this->headers)->assertStatus(409);
});

it('membuat menu dengan varian, modifier, dan riwayat harga (FR-MENU-02..04, FR-MENU-15)', function () {
    $category = Menu::category($this->company, $this->brand);
    $sugar = Menu::modifierGroup($this->company, $this->brand, [['name' => 'Normal', 'price' => '0'], ['name' => 'Less', 'price' => '0']], 1, 1);
    $bar = Menu::station($this->company);

    $response = $this->postJson('/api/v1/items', [
        'brand_id' => $this->brand->id,
        'category_id' => $category->id,
        'sku' => 'KSA-01',
        'name' => 'Kopi Susu Gula Aren',
        'base_price' => '18000',
        'kitchen_station_id' => $bar->id,
        'channel_codes' => ['dine_in', 'take_away', 'gofood'],
        'variants' => [
            ['name' => 'Regular', 'price' => '18000', 'is_default' => true],
            ['name' => 'Large', 'price' => '22000'],
        ],
        'modifier_group_ids' => [$sugar->id],
    ], $this->headers)->assertCreated();

    $item = $response->json('data');
    expect($item['short_name'])->toBe('Kopi Susu Gula Aren')
        ->and($item['variants'])->toHaveCount(2)
        ->and($item['modifier_groups'][0]['id'])->toBe($sugar->id)
        ->and($item['channel_codes'])->toBe(['dine_in', 'take_away', 'gofood']);

    // SKU unik per brand (tidak peka huruf).
    $dup = $this->postJson('/api/v1/items', [
        'brand_id' => $this->brand->id, 'category_id' => $category->id, 'sku' => 'ksa-01', 'name' => 'Ganda', 'base_price' => '1',
    ], $this->headers)->assertUnprocessable();
    expect(errorFields($dup))->toContain('sku');

    // Harga tidak boleh negatif / lebih dari 2 desimal.
    $bad = $this->postJson('/api/v1/items', [
        'brand_id' => $this->brand->id, 'category_id' => $category->id, 'sku' => 'NEG', 'name' => 'Salah', 'base_price' => '-1',
        'variants' => [['name' => 'A', 'price' => '10.123']],
    ], $this->headers)->assertUnprocessable();
    expect(errorFields($bad))->toContain('base_price', 'variants.0.price');

    // Kategori brand lain ditolak.
    $foreignCategory = Menu::category($this->company, $this->otherBrand);
    $this->postJson('/api/v1/items', [
        'brand_id' => $this->brand->id, 'category_id' => $foreignCategory->id, 'sku' => 'X', 'name' => 'X', 'base_price' => '1',
    ], $this->headers)->assertUnprocessable();

    // Ubah harga varian → tercatat di riwayat.
    $large = collect($item['variants'])->firstWhere('name', 'Large');
    $this->patchJson("/api/v1/items/{$item['id']}", [
        'base_price' => '19000',
        'variants' => [
            ['id' => collect($item['variants'])->firstWhere('name', 'Regular')['id'], 'name' => 'Regular', 'price' => '19000', 'is_default' => true],
            ['id' => $large['id'], 'name' => 'Large', 'price' => '23000'],
        ],
    ], $this->headers)->assertOk()->assertJsonPath('data.base_price', '19000.00');

    $history = $this->getJson("/api/v1/items/{$item['id']}/price-history", $this->headers)->assertOk()->json('data');
    $largeChange = collect($history)->first(fn ($h) => $h['item_variant_id'] === $large['id'] && $h['old_price'] !== null);
    expect($largeChange['old_price'])->toBe('22000.00')
        ->and($largeChange['new_price'])->toBe('23000.00')
        ->and($largeChange['changed_by']['id'])->toBe($this->owner->id);

    // Hapus → soft delete dan tidak muncul di daftar.
    $this->deleteJson("/api/v1/items/{$item['id']}", [], $this->headers)->assertOk();
    $this->getJson("/api/v1/items/{$item['id']}", $this->headers)->assertNotFound();
    expect(Factory::tenant($this->company, fn () => ItemPriceHistory::query()->where('item_id', $item['id'])->count()))->toBeGreaterThan(3);
});

it('membuat menu paket (FR-MENU-05)', function () {
    $coffee = Menu::item($this->company, $this->brand, ['name' => 'Kopi Hitam', 'base_price' => '15000']);
    $tea = Menu::item($this->company, $this->brand, ['name' => 'Teh Tarik', 'base_price' => '16000']);
    $toast = Menu::item($this->company, $this->brand, ['name' => 'Roti Bakar', 'base_price' => '20000']);
    $foreign = Menu::item($this->company, $this->otherBrand, ['name' => 'Roti Lain']);

    $payload = [
        'brand_id' => $this->brand->id,
        'category_id' => $coffee->category_id,
        'type' => 'bundle',
        'sku' => 'PKT-PAGI',
        'name' => 'Paket Sarapan',
        'base_price' => '30000',
        'bundle_groups' => [
            ['name' => 'Minuman', 'min_select' => 1, 'max_select' => 1, 'options' => [
                ['item_id' => $coffee->id, 'is_default' => true],
                ['item_id' => $tea->id, 'extra_price' => '2000'],
            ]],
            ['name' => 'Makanan', 'min_select' => 1, 'max_select' => 1, 'options' => [['item_id' => $toast->id]]],
        ],
    ];

    $bundle = $this->postJson('/api/v1/items', $payload, $this->headers)->assertCreated()->json('data');
    expect($bundle['type'])->toBe('bundle')->and($bundle['bundle_groups'])->toHaveCount(2)
        ->and($bundle['bundle_groups'][0]['options'][1]['extra_price'])->toBe('2000.00');

    // Isi paket dari brand lain atau paket di dalam paket ditolak.
    $payload['sku'] = 'PKT-2';
    $payload['bundle_groups'][1]['options'] = [['item_id' => $foreign->id]];
    $this->postJson('/api/v1/items', $payload, $this->headers)->assertUnprocessable();

    $payload['bundle_groups'][1]['options'] = [['item_id' => $bundle['id']]];
    $this->postJson('/api/v1/items', $payload, $this->headers)->assertUnprocessable();

    // min > max ditolak.
    $payload['bundle_groups'][1] = ['name' => 'Makanan', 'min_select' => 2, 'max_select' => 1, 'options' => [['item_id' => $toast->id]]];
    $this->postJson('/api/v1/items', $payload, $this->headers)->assertUnprocessable();
});

it('mengelola grup modifier (FR-MENU-04)', function () {
    $group = $this->postJson('/api/v1/modifier-groups', [
        'brand_id' => $this->brand->id, 'name' => 'Topping', 'min_select' => 0, 'max_select' => 3,
        'modifiers' => [['name' => 'Boba', 'price' => '5000'], ['name' => 'Cheese Foam', 'price' => '7000']],
    ], $this->headers)->assertCreated()->json('data');
    expect($group['required'])->toBeFalse()->and($group['modifiers'])->toHaveCount(2);

    $this->postJson('/api/v1/modifier-groups', [
        'brand_id' => $this->brand->id, 'name' => 'Salah', 'min_select' => 3, 'max_select' => 1,
        'modifiers' => [['name' => 'A', 'price' => '0']],
    ], $this->headers)->assertUnprocessable();

    $boba = $group['modifiers'][0];
    $updated = $this->patchJson("/api/v1/modifier-groups/{$group['id']}", [
        'modifiers' => [['id' => $boba['id'], 'name' => 'Boba', 'price' => '6000']],
    ], $this->headers)->assertOk()->json('data');
    expect($updated['modifiers'])->toHaveCount(1)->and($updated['modifiers'][0]['price'])->toBe('6000.00');

    $this->deleteJson("/api/v1/modifier-groups/{$group['id']}", [], $this->headers)->assertOk();
});

it('mengatur harga khusus per outlet dan channel (FR-MENU-06, FR-MENU-07)', function () {
    $item = Menu::item($this->company, $this->brand, ['base_price' => '18000']);
    $gofood = Menu::channel($this->company, 'gofood');

    $this->putJson("/api/v1/items/{$item->id}/prices", ['prices' => [
        ['outlet_id' => $this->kemang->id, 'price' => '20000'],
        ['sales_channel_id' => $gofood->id, 'price' => '22500'],
        ['outlet_id' => $this->kemang->id, 'sales_channel_id' => $gofood->id, 'price' => '24000'],
    ]], $this->headers)->assertOk()->assertJsonCount(3, 'data');

    $this->putJson("/api/v1/items/{$item->id}/prices", ['prices' => [
        ['outlet_id' => $this->kemang->id, 'price' => '20000'],
        ['outlet_id' => $this->kemang->id, 'price' => '21000'],
    ]], $this->headers)->assertUnprocessable();
    $this->putJson("/api/v1/items/{$item->id}/prices", ['prices' => [['price' => '1']]], $this->headers)->assertUnprocessable();

    // Outlet brand lain ditolak.
    $foreignOutlet = Factory::outlet($this->company, $this->otherBrand);
    $this->putJson("/api/v1/items/{$item->id}/prices", ['prices' => [['outlet_id' => $foreignOutlet->id, 'price' => '1']]], $this->headers)
        ->assertUnprocessable();

    $prices = $this->getJson("/api/v1/items/{$item->id}/prices", $this->headers)->assertOk()->json('data');
    expect($prices)->toHaveCount(3);

    // Mengosongkan harga khusus → riwayat mencatat penghapusan.
    $this->putJson("/api/v1/items/{$item->id}/prices", ['prices' => []], $this->headers)->assertOk()->assertJsonCount(0, 'data');
    $removed = Factory::tenant($this->company, fn () => ItemPriceHistory::query()->where('item_id', $item->id)->whereNull('new_price')->count());
    expect($removed)->toBe(3);
});

it('mengatur ketersediaan & menu habis per outlet dengan audit (FR-MENU-08)', function () {
    Event::fake([ItemAvailabilityChanged::class]);
    $item = Menu::item($this->company, $this->brand, ['name' => 'Croissant']);

    $this->putJson("/api/v1/items/{$item->id}/availability", ['outlet_id' => $this->kemang->id, 'is_sold_out' => true], $this->headers)
        ->assertOk()->assertJsonPath('data.is_sold_out', true)->assertJsonPath('data.is_listed', true);

    $list = $this->getJson("/api/v1/items/{$item->id}/availability", $this->headers)->assertOk()->json('data');
    expect(collect($list)->firstWhere('outlet_id', $this->kemang->id)['is_sold_out'])->toBeTrue()
        ->and(collect($list)->firstWhere('outlet_id', $this->dago->id)['is_sold_out'])->toBeFalse();

    Event::assertDispatched(ItemAvailabilityChanged::class, fn ($e) => $e->itemId === $item->id && $e->soldOut === true);
    $log = Factory::tenant($this->company, fn () => AuditLog::query()->where('action', 'item.availability_changed')->first());
    expect($log)->not->toBeNull()->and($log->user_id)->toBe($this->owner->id);

    $otherOutlet = Factory::outlet($this->company, $this->otherBrand);
    $this->putJson("/api/v1/items/{$item->id}/availability", ['outlet_id' => $otherOutlet->id, 'is_sold_out' => true], $this->headers)
        ->assertUnprocessable();
});

it('membatasi akses menu sesuai peran dan cakupan', function () {
    $item = Menu::item($this->company, $this->brand);
    $otherItem = Menu::item($this->company, $this->otherBrand);
    $category = Menu::category($this->company, $this->brand);

    [$brandManager] = Factory::staff($this->company, ['brand_manager'], [], null, [$this->brand->id]);
    [$outletManager] = Factory::staff($this->company, ['outlet_manager'], [$this->kemang->id]);
    [$cashier] = Factory::staff($this->company, ['cashier'], [$this->kemang->id]);

    // Manajer brand: kelola brand sendiri saja.
    $bm = asMember($brandManager, $this->company);
    $ids = collect($this->getJson('/api/v1/items', $bm)->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toContain($item->id)->not->toContain($otherItem->id);
    $this->patchJson("/api/v1/items/{$item->id}", ['name' => 'Ubah'], $bm)->assertOk();
    $this->patchJson("/api/v1/items/{$otherItem->id}", ['name' => 'Ubah'], $bm)->assertForbidden();
    $this->getJson("/api/v1/items/{$otherItem->id}", $bm)->assertForbidden();
    $this->postJson('/api/v1/items', [
        'brand_id' => $this->otherBrand->id, 'category_id' => $otherItem->category_id, 'sku' => 'BM-1', 'name' => 'X', 'base_price' => '1',
    ], $bm)->assertForbidden();
    $this->postJson('/api/v1/kitchen-stations', ['code' => 'BM', 'name' => 'X'], $bm)->assertForbidden();

    // Manajer outlet: lihat saja, boleh tandai habis di outlet sendiri.
    $om = asMember($outletManager, $this->company);
    $this->getJson("/api/v1/items/{$item->id}", $om)->assertOk();
    $this->patchJson("/api/v1/items/{$item->id}", ['name' => 'Ubah'], $om)->assertForbidden();
    $this->postJson('/api/v1/menu-categories', ['brand_id' => $this->brand->id, 'name' => 'Baru'], $om)->assertForbidden();
    $this->patchJson("/api/v1/menu-categories/{$category->id}", ['name' => 'Baru'], $om)->assertForbidden();
    $this->putJson("/api/v1/items/{$item->id}/prices", ['prices' => []], $om)->assertForbidden();
    $this->putJson("/api/v1/items/{$item->id}/availability", ['outlet_id' => $this->kemang->id, 'is_sold_out' => true], $om)->assertOk();
    $this->putJson("/api/v1/items/{$item->id}/availability", ['outlet_id' => $this->dago->id, 'is_sold_out' => true], $om)->assertForbidden();
    $this->putJson("/api/v1/items/{$item->id}/availability", ['outlet_id' => $this->kemang->id, 'is_listed' => false], $om)->assertForbidden();

    // Kasir tidak punya akses back-office menu.
    $cs = asMember($cashier, $this->company);
    $this->getJson('/api/v1/items', $cs)->assertForbidden();
    $this->getJson('/api/v1/promotions', $cs)->assertForbidden();
});

it('menolak perubahan menu dalam mode baca-saja langganan (FR-TEN-08)', function () {
    $item = Menu::item($this->company, $this->brand);
    Factory::system(fn () => $this->company->forceFill(['subscription_ends_at' => now()->subDays(8)])->save());

    $this->getJson('/api/v1/items', $this->headers)->assertOk();
    $this->getJson("/api/v1/items/{$item->id}", $this->headers)->assertOk();
    $blocked = $this->patchJson("/api/v1/items/{$item->id}", ['name' => 'Ubah'], $this->headers)->assertStatus(402);
    expect(firstErrorCode($blocked))->toBe('SUBSCRIPTION_READ_ONLY');
    $this->postJson('/api/v1/promotions', ['name' => 'X', 'type' => 'percent', 'value' => '5', 'starts_at' => now()->toIso8601String()], $this->headers)
        ->assertStatus(402);
    $this->putJson("/api/v1/items/{$item->id}/availability", ['outlet_id' => $this->kemang->id, 'is_sold_out' => true], $this->headers)
        ->assertStatus(402);
});

it('menolak perubahan menu saat company ditangguhkan', function () {
    Factory::system(fn () => $this->company->forceFill(['status' => CompanyStatus::Suspended])->save());
    $this->getJson('/api/v1/items', $this->headers)->assertForbidden();
});

it('mencari menu dan memfilter kategori', function () {
    $category = Menu::category($this->company, $this->brand, 'Pastry');
    Menu::item($this->company, $this->brand, ['name' => 'Croissant Cokelat', 'category_id' => $category->id, 'sku' => 'CRS-1']);
    Menu::item($this->company, $this->brand, ['name' => 'Es Kopi 100%']);

    $this->getJson('/api/v1/items?search=croiss', $this->headers)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/items?search=%25', $this->headers)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/items?category_id='.$category->id, $this->headers)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/items?per_page=1', $this->headers)->assertOk()->assertJsonPath('meta.pagination.total', 2);
});

it('menampilkan pesan validasi dalam Bahasa Indonesia', function () {
    $r = $this->postJson('/api/v1/items', ['brand_id' => 'bukan-uuid'], $this->headers)->assertUnprocessable();

    $messages = collect($r->json('errors'))->pluck('message', 'field');
    expect($messages['brand_id'])->toContain('Brand')->toContain('ID yang valid')
        ->and($messages['name'])->toBe('Nama menu wajib diisi.');
});
