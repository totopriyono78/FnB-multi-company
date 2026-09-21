<?php

use Tests\Support\Factory;
use Tests\Support\Menu;

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->brand = Factory::brand($this->company, ['code' => 'KTJ']);
    $this->otherBrand = Factory::brand($this->company, ['code' => 'RB8']);
    $this->kemang = Factory::outlet($this->company, $this->brand, ['code' => 'KMG']);
    $this->coffee = Menu::item($this->company, $this->brand, ['name' => 'Kopi Hitam']);
    $this->headers = asMember($this->owner, $this->company);
    $this->payload = fn (array $extra = []) => $extra + [
        'brand_id' => $this->brand->id,
        'name' => 'Happy Hour Kopi',
        'type' => 'percent',
        'value' => '20',
        'scope' => 'items',
        'item_ids' => [$this->coffee->id],
        'outlet_ids' => [$this->kemang->id],
        'channel_codes' => ['dine_in'],
        'days_of_week' => [1, 2, 3, 4, 5],
        'time_start' => '14:00',
        'time_end' => '17:00',
        'starts_at' => '2026-09-01T00:00:00+07:00',
        'ends_at' => '2026-12-31T23:59:59+07:00',
        'max_discount' => '10000',
        'quota' => 500,
    ];
});

it('membuat, mengubah, dan menghapus promo (FR-MENU-12)', function () {
    $promo = $this->postJson('/api/v1/promotions', ($this->payload)(), $this->headers)->assertCreated()->json('data');

    expect($promo)->toMatchArray([
        'name' => 'Happy Hour Kopi', 'type' => 'percent', 'value' => '20.00', 'scope' => 'items',
        'item_ids' => [$this->coffee->id], 'outlet_ids' => [$this->kemang->id], 'channel_codes' => ['dine_in'],
        'time_start' => '14:00', 'time_end' => '17:00', 'max_discount' => '10000.00', 'quota' => 500, 'used_count' => 0,
    ]);

    // Mengubah item saja tidak menghapus target kategori.
    $category = Menu::category($this->company, $this->brand);
    $this->patchJson("/api/v1/promotions/{$promo['id']}", ['category_ids' => [$category->id]], $this->headers)->assertOk();
    $updated = $this->patchJson("/api/v1/promotions/{$promo['id']}", ['item_ids' => []], $this->headers)->assertOk()->json('data');
    expect($updated['item_ids'])->toBe([])->and($updated['category_ids'])->toBe([$category->id]);

    // Brand promo tidak dapat dipindah.
    $this->patchJson("/api/v1/promotions/{$promo['id']}", ['brand_id' => $this->otherBrand->id], $this->headers)->assertUnprocessable();

    $this->getJson('/api/v1/promotions?search=happy', $this->headers)->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/promotions/{$promo['id']}", [], $this->headers)->assertOk();
    $this->getJson("/api/v1/promotions/{$promo['id']}", $this->headers)->assertNotFound();
});

it('memvalidasi aturan promo', function (array $override, string $field) {
    $response = $this->postJson('/api/v1/promotions', ($this->payload)($override), $this->headers)->assertUnprocessable();
    expect(errorFields($response))->toContain($field);
})->with([
    'persen > 100' => [['value' => '120'], 'value'],
    'nominal nol' => [['type' => 'amount', 'value' => '0'], 'value'],
    'beli X gratis Y tanpa jumlah' => [['type' => 'buy_x_get_y', 'value' => '0'], 'buy_qty'],
    'harga spesial untuk order' => [['type' => 'special_price', 'value' => '10000', 'scope' => 'order', 'item_ids' => []], 'scope'],
    'akhir sebelum mulai' => [['ends_at' => '2026-08-01T00:00:00+07:00'], 'ends_at'],
    'jam sama' => [['time_end' => '14:00'], 'time_end'],
    'hari tidak valid' => [['days_of_week' => [0]], 'days_of_week.0'],
    'kode bersimbol' => [['code' => 'HEMAT 20%'], 'code'],
    'metode bayar tak dikenal' => [['payment_methods' => ['bitcoin']], 'payment_methods.0'],
    'channel tak dikenal' => [['channel_codes' => ['tokopedia']], 'channel_codes.0'],
]);

it('menolak menu atau outlet dari brand lain', function () {
    $foreignItem = Menu::item($this->company, $this->otherBrand);
    $foreignOutlet = Factory::outlet($this->company, $this->otherBrand);

    $r = $this->postJson('/api/v1/promotions', ($this->payload)(['item_ids' => [$foreignItem->id]]), $this->headers)->assertUnprocessable();
    expect(errorFields($r))->toContain('item_ids');
    $r = $this->postJson('/api/v1/promotions', ($this->payload)(['outlet_ids' => [$foreignOutlet->id]]), $this->headers)->assertUnprocessable();
    expect(errorFields($r))->toContain('outlet_ids');
});

it('kode promo unik per company', function () {
    $this->postJson('/api/v1/promotions', ($this->payload)(['code' => ' kopi20 ']), $this->headers)
        ->assertCreated()->assertJsonPath('data.code', 'KOPI20');
    $r = $this->postJson('/api/v1/promotions', ($this->payload)(['code' => 'Kopi20', 'brand_id' => null, 'item_ids' => [], 'outlet_ids' => [], 'scope' => 'order']), $this->headers)
        ->assertUnprocessable();
    expect(errorFields($r))->toContain('code');
});

it('membatasi pengelolaan promo sesuai brand', function () {
    [$brandManager] = Factory::staff($this->company, ['brand_manager'], [], null, [$this->brand->id]);
    [$outletManager] = Factory::staff($this->company, ['outlet_manager'], [$this->kemang->id]);
    $companyWide = Menu::promotion($this->company, ['name' => 'Promo Semua Brand']);
    $bm = asMember($brandManager, $this->company);

    $this->postJson('/api/v1/promotions', ($this->payload)(), $bm)->assertCreated();
    $foreignItem = Menu::item($this->company, $this->otherBrand);
    $this->postJson('/api/v1/promotions', ($this->payload)(['brand_id' => $this->otherBrand->id, 'item_ids' => [$foreignItem->id], 'outlet_ids' => []]), $bm)
        ->assertForbidden();
    // Promo seluruh company hanya dapat dikelola pengguna tingkat company, tetapi terlihat oleh semua.
    $this->postJson('/api/v1/promotions', ($this->payload)(['brand_id' => null, 'item_ids' => [], 'outlet_ids' => [], 'scope' => 'order']), $bm)
        ->assertForbidden();
    $this->getJson("/api/v1/promotions/{$companyWide->id}", $bm)->assertOk();
    $this->patchJson("/api/v1/promotions/{$companyWide->id}", ['name' => 'Ubah'], $bm)->assertForbidden();

    $om = asMember($outletManager, $this->company);
    $this->getJson('/api/v1/promotions', $om)->assertOk();
    $this->postJson('/api/v1/promotions', ($this->payload)(), $om)->assertForbidden();
});
