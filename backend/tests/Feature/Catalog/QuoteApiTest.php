<?php

use App\Modules\Catalog\Domain\Models\ItemPrice;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Tests\Support\Factory;
use Tests\Support\Menu;

/**
 * Simulasi harga end-to-end: konfigurasi outlet + channel + harga khusus + promo → PricingCalculator.
 * Angka harapan dihitung manual (lihat komentar tiap kasus), bukan disalin dari keluaran kode.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->brand = Factory::brand($this->company, ['code' => 'KTJ']);
    $this->kemang = Factory::outlet($this->company, $this->brand, ['code' => 'KMG']);
    Factory::tenant($this->company, fn () => Outlet::query()->whereKey($this->kemang->id)->update([
        'tax_name' => 'PB1', 'tax_rate' => '10', 'tax_inclusive' => false, 'tax_on_service_charge' => true,
        'service_charge_rate' => '5', 'rounding_unit' => 100, 'rounding_mode' => 'nearest',
    ]));
    $this->dago = Factory::outlet($this->company, $this->brand, ['code' => 'DGO']);

    $this->topping = Menu::modifierGroup($this->company, $this->brand, [
        ['name' => 'Boba', 'price' => '5000'], ['name' => 'Cheese Foam', 'price' => '7000'],
    ], 0, 2, 'Topping');
    $this->sugar = Menu::modifierGroup($this->company, $this->brand, [
        ['name' => 'Normal', 'price' => '0'], ['name' => 'Less Sugar', 'price' => '0'],
    ], 1, 1, 'Gula');

    $this->coffee = Menu::item($this->company, $this->brand, [
        'name' => 'Kopi Susu Gula Aren', 'sku' => 'KSA', 'base_price' => '18000',
        'channel_codes' => ['dine_in', 'take_away', 'gofood'],
        'variants' => [['name' => 'Regular', 'price' => '18000', 'is_default' => true], ['name' => 'Large', 'price' => '22000']],
        'modifier_group_ids' => [$this->sugar->id, $this->topping->id],
    ]);
    $this->croissant = Menu::item($this->company, $this->brand, ['name' => 'Croissant', 'sku' => 'CRS', 'base_price' => '25000']);

    $this->large = $this->coffee->variants->firstWhere('name', 'Large');
    $this->boba = $this->topping->modifiers->firstWhere('name', 'Boba');
    $this->normal = $this->sugar->modifiers->firstWhere('name', 'Normal');
    $this->headers = asMember($this->owner, $this->company);

    $this->cart = fn (array $extra = []) => $extra + [
        'outlet_id' => $this->kemang->id,
        'channel_code' => 'dine_in',
        'lines' => [
            ['id' => 'l1', 'item_id' => $this->coffee->id, 'variant_id' => $this->large->id, 'qty' => 2,
                'modifiers' => [['id' => $this->normal->id], ['id' => $this->boba->id]]],
            ['id' => 'l2', 'item_id' => $this->croissant->id, 'qty' => 1],
        ],
    ];
});

it('menghitung total dine-in dengan SC, pajak atas SC, dan pembulatan Rp100', function () {
    // (22.000 + 5.000) × 2 = 54.000; croissant 25.000 → subtotal 79.000
    // SC 5% = 3.950; PB1 10% × (79.000 + 3.950) = 8.295; total 91.245 → 91.200 (pembulatan −45)
    $r = $this->postJson('/api/v1/quotes', ($this->cart)(), $this->headers)->assertOk();

    expect($r->json('data.totals'))->toMatchArray([
        'subtotal' => '79000.00', 'discount' => '0.00', 'service_charge' => '3950.00',
        'tax_base' => '82950.00', 'tax' => '8295.00', 'total_before_rounding' => '91245.00',
        'rounding' => '-45.00', 'total' => '91200.00',
    ]);
    expect($r->json('data.lines.0'))->toMatchArray(['id' => 'l1', 'name' => 'Kopi Susu Gula Aren', 'unit_price' => '22000.00', 'gross' => '54000.00'])
        ->and($r->json('data.lines.0.variant.name'))->toBe('Large')
        ->and($r->json('data.tax'))->toBe(['name' => 'PB1', 'rate' => '10.00', 'inclusive' => false])
        ->and($r->json('data.channel.code'))->toBe('dine_in');
});

it('tidak mengenakan SC untuk take away dan memakai harga channel', function () {
    // Harga GoFood croissant 30.000 berlaku hanya untuk channel gofood.
    Factory::tenant($this->company, fn () => ItemPrice::query()->create([
        'item_id' => $this->croissant->id, 'sales_channel_id' => Menu::channel($this->company, 'gofood')->id, 'price' => '30000',
    ]));

    // Take away: subtotal 79.000; tanpa SC; pajak 7.900; total 86.900 (tanpa pembulatan)
    $take = $this->postJson('/api/v1/quotes', ($this->cart)(['channel_code' => 'take_away']), $this->headers)->assertOk();
    expect($take->json('data.totals'))->toMatchArray(['subtotal' => '79000.00', 'service_charge' => '0.00', 'tax' => '7900.00', 'rounding' => '0.00', 'total' => '86900.00']);

    // GoFood: croissant 30.000 → subtotal 84.000; pajak 8.400; total 92.400
    $go = $this->postJson('/api/v1/quotes', ($this->cart)(['channel_code' => 'gofood']), $this->headers)->assertOk();
    expect($go->json('data.totals'))->toMatchArray(['subtotal' => '84000.00', 'service_charge' => '0.00', 'tax' => '8400.00', 'total' => '92400.00']);
});

it('memakai harga outlet dan harga pajak inklusif', function () {
    Factory::tenant($this->company, function () {
        ItemPrice::query()->create(['item_id' => $this->croissant->id, 'outlet_id' => $this->dago->id, 'price' => '27500']);
        Outlet::query()->whereKey($this->dago->id)->update([
            'tax_rate' => '10', 'tax_inclusive' => true, 'service_charge_rate' => '0', 'rounding_unit' => 0,
        ]);
    });

    // Harga termasuk pajak 27.500 → pajak = 27.500 × 10/110 = 2.500; total tetap 27.500
    $r = $this->postJson('/api/v1/quotes', [
        'outlet_id' => $this->dago->id,
        'lines' => [['item_id' => $this->croissant->id, 'qty' => 1]],
    ], $this->headers)->assertOk();
    expect($r->json('data.totals'))->toMatchArray(['subtotal' => '27500.00', 'tax' => '2500.00', 'total' => '27500.00', 'rounding' => '0.00']);
});

it('menerapkan promo otomatis per outlet dan kode promo (BR-18)', function () {
    Menu::promotion($this->company, ['name' => 'Croissant Hemat', 'type' => 'percent', 'value' => '10', 'brand_id' => $this->brand->id], [$this->croissant->id], [$this->kemang->id]);
    Menu::promotion($this->company, ['name' => 'Kode Hemat', 'code' => 'HEMAT5', 'type' => 'amount', 'value' => '5000', 'auto_apply' => false, 'stackable' => true, 'scope' => 'order']);

    // Diskon croissant 10% = 2.500 → neto 76.500; SC 3.825; pajak 10% × 80.325 = 8.032,50
    // total 88.357,50 → 88.400 (pembulatan +42,50)
    $r = $this->postJson('/api/v1/quotes', ($this->cart)(), $this->headers)->assertOk();
    expect($r->json('data.totals'))->toMatchArray([
        'subtotal' => '79000.00', 'discount' => '2500.00', 'service_charge' => '3825.00',
        'tax' => '8032.50', 'rounding' => '42.50', 'total' => '88400.00',
    ]);
    expect(collect($r->json('data.promotions'))->pluck('name')->all())->toBe(['Croissant Hemat']);

    // Promo hanya untuk Kemang → tidak berlaku di Dago.
    $dago = $this->postJson('/api/v1/quotes', ($this->cart)(['outlet_id' => $this->dago->id]), $this->headers)->assertOk();
    expect($dago->json('data.totals.discount'))->toBe('0.00');

    // Kode promo (stackable) + promo item (non-stackable): dipilih yang lebih besar → 5.000
    // neto 74.000; SC 3.700; pajak 7.770; total 85.470 → 85.500
    $coded = $this->postJson('/api/v1/quotes', ($this->cart)(['promo_codes' => ['HEMAT5']]), $this->headers)->assertOk();
    expect($coded->json('data.totals'))->toMatchArray(['discount' => '5000.00', 'service_charge' => '3700.00', 'tax' => '7770.00', 'total' => '85500.00']);
    expect(collect($coded->json('data.promotions'))->pluck('name')->all())->toBe(['Kode Hemat']);

    // Kontrak dengan kasir: rincian diskon (jenis, nilai, sumber) ikut dikembalikan, baik per baris
    // maupun per transaksi, supaya perangkat bisa mengirimkannya kembali saat menyimpan pesanan.
    // Tanpa ini promo otomatis membuat POST /pos/orders ditolak TOTAL_MISMATCH.
    $baris = collect($r->json('data.lines'))->firstWhere('item_id', $this->croissant->id);
    expect($baris['discounts'])->toHaveCount(1)
        // Mesin promo sudah mengubah 10% menjadi nominal, jadi yang dikirim balik ke server
        // adalah angka rupiah yang sama persis — bukan persentase yang dihitung ulang.
        ->and($baris['discounts'][0]['type'])->toBe('amount')
        ->and($baris['discounts'][0]['value'])->toBe('2500.00')
        ->and($baris['discounts'][0]['source'])->toStartWith('promo:');
    expect($r->json('data.order_discounts'))->toBe([]);
    expect($coded->json('data.order_discounts'))->toHaveCount(1)
        ->and($coded->json('data.order_discounts.0.type'))->toBe('amount')
        ->and($coded->json('data.order_discounts.0.source'))->toStartWith('promo:');
});

it('memvalidasi pilihan modifier, varian, channel, dan ketersediaan', function () {
    $post = fn (array $cart) => $this->postJson('/api/v1/quotes', $cart, $this->headers);

    // Grup Gula wajib dipilih (min 1).
    $cart = ($this->cart)();
    $cart['lines'][0]['modifiers'] = [['id' => $this->boba->id]];
    $post($cart)->assertUnprocessable();

    // Modifier dari grup yang tidak terpasang di menu.
    $foreign = Menu::modifierGroup($this->company, $this->brand, [['name' => 'Saus', 'price' => '0']]);
    $cart = ($this->cart)();
    $cart['lines'][1]['modifiers'] = [['id' => $foreign->modifiers->first()->id]];
    $post($cart)->assertUnprocessable();

    // Varian milik menu lain.
    $cart = ($this->cart)();
    $cart['lines'][1]['variant_id'] = $this->large->id;
    $post($cart)->assertUnprocessable();

    // Kopi hanya dijual di dine_in/take_away/gofood; channel tak dikenal juga ditolak.
    $post(($this->cart)(['channel_code' => 'grabfood']))->assertUnprocessable();
    $post(($this->cart)(['channel_code' => 'tidak_ada']))->assertUnprocessable();

    // Menu habis di outlet.
    $this->putJson("/api/v1/items/{$this->croissant->id}/availability", ['outlet_id' => $this->kemang->id, 'is_sold_out' => true], $this->headers)->assertOk();
    $sold = $post(($this->cart)())->assertUnprocessable();
    expect($sold->json('errors.0.message'))->toContain('habis');

    // Qty dan diskon manual tidak valid.
    $cart = ($this->cart)();
    $cart['lines'][0]['qty'] = 0;
    $post($cart)->assertUnprocessable();
    $post(['outlet_id' => $this->kemang->id, 'lines' => []])->assertUnprocessable();
});

it('menghormati jadwal jual menu (FR-MENU-14)', function () {
    $breakfast = Menu::item($this->company, $this->brand, [
        'name' => 'Nasi Uduk Pagi', 'base_price' => '15000',
        'schedule' => [['days' => [1, 2, 3, 4, 5], 'start' => '06:00', 'end' => '10:00']],
    ]);
    $cart = fn (string $at) => ['outlet_id' => $this->kemang->id, 'at' => $at, 'lines' => [['item_id' => $breakfast->id, 'qty' => 1]]];

    // Selasa 15-09-2026 07:30 WIB = 00:30 UTC → tersedia; 11:00 WIB → tidak; Sabtu → tidak.
    $this->postJson('/api/v1/quotes', $cart('2026-09-15T00:30:00Z'), $this->headers)->assertOk();
    $this->postJson('/api/v1/quotes', $cart('2026-09-15T04:00:00Z'), $this->headers)->assertUnprocessable();
    $this->postJson('/api/v1/quotes', $cart('2026-09-19T00:30:00Z'), $this->headers)->assertUnprocessable();
});

it('membatasi simulasi harga pada outlet dalam cakupan user', function () {
    [$dagoManager] = Factory::staff($this->company, ['outlet_manager'], [$this->dago->id]);
    [$kitchen] = Factory::staff($this->company, ['kitchen'], [$this->kemang->id]);

    $this->postJson('/api/v1/quotes', ($this->cart)(), asMember($dagoManager, $this->company))->assertForbidden();
    $this->postJson('/api/v1/quotes', ($this->cart)(['outlet_id' => $this->dago->id]), asMember($dagoManager, $this->company))->assertOk();
    $this->postJson('/api/v1/quotes', ($this->cart)(), asMember($kitchen, $this->company))->assertForbidden();
});

describe('endpoint POS', function () {
    beforeEach(function () {
        [$this->device, $this->deviceToken] = Factory::pairedDevice($this->company, $this->kemang);
        [$this->cashier, $this->cashierMember] = Factory::staff($this->company, ['cashier'], [$this->kemang->id], '7351');
        $this->posToken = $this->postJson('/api/v1/pos/auth/pin', ['staff_id' => $this->cashierMember->id, 'pin' => '7351'], bearer($this->deviceToken))
            ->assertOk()->json('data.token');
    });

    it('mengirim snapshot menu outlet ke POS (FR-DEV-03)', function () {
        Factory::tenant($this->company, fn () => ItemPrice::query()->create([
            'item_id' => $this->croissant->id, 'outlet_id' => $this->kemang->id, 'price' => '26000',
        ]));
        $hidden = Menu::item($this->company, $this->brand, ['name' => 'Tidak Dijual Di Kemang']);
        $this->putJson("/api/v1/items/{$hidden->id}/availability", ['outlet_id' => $this->kemang->id, 'is_listed' => false], $this->headers)->assertOk();
        $otherBrand = Factory::brand($this->company);
        $foreign = Menu::item($this->company, $otherBrand, ['name' => 'Menu Brand Lain']);

        $r = $this->getJson('/api/v1/pos/catalog', bearer($this->deviceToken))->assertOk();
        $data = $r->json('data');

        expect($data['outlet']['pricing'])->toMatchArray(['tax_rate' => '10.00', 'service_charge_rate' => '5.00', 'rounding_unit' => 100]);
        // Jumlah meja dipakai layar kasir untuk tombol pintas nomor meja (FR-POS).
        expect($data['outlet'])->toHaveKey('table_count')
            ->and($data['outlet']['table_count'])->toBeInt();
        $ids = collect($data['items'])->pluck('id')->all();
        expect($ids)->toContain($this->coffee->id, $this->croissant->id)->not->toContain($hidden->id, $foreign->id);

        $croissant = collect($data['items'])->firstWhere('id', $this->croissant->id);
        expect($croissant['prices']['dine_in'])->toBe('26000.00');
        $coffee = collect($data['items'])->firstWhere('id', $this->coffee->id);
        expect(array_keys($coffee['variants'][1]['prices']))->toBe(['dine_in', 'take_away', 'gofood'])
            ->and($coffee['variants'][1]['prices']['dine_in'])->toBe('22000.00');
        expect(collect($data['modifier_groups'])->pluck('id')->all())->toContain($this->topping->id, $this->sugar->id);

        // Token kasir juga boleh.
        $this->getJson('/api/v1/pos/catalog', bearer($this->posToken))->assertOk();
        // Token back-office tidak.
        $this->getJson('/api/v1/pos/catalog', $this->headers)->assertForbidden();
    });

    it('menghitung harga dari POS tanpa memilih outlet', function () {
        $cart = ($this->cart)();
        unset($cart['outlet_id']);
        $this->postJson('/api/v1/pos/quotes', $cart, bearer($this->posToken))->assertOk()->assertJsonPath('data.totals.total', '91200.00');

        // Mengirim outlet_id dilarang agar POS tidak bisa menghitung untuk outlet lain.
        $this->postJson('/api/v1/pos/quotes', $cart + ['outlet_id' => $this->dago->id], bearer($this->posToken))->assertUnprocessable();
    });

    it('kasir menandai menu habis dari POS (FR-MENU-08)', function () {
        $this->postJson("/api/v1/pos/items/{$this->croissant->id}/sold-out", ['sold_out' => true], bearer($this->posToken))
            ->assertOk()->assertJsonPath('data.is_sold_out', true)->assertJsonPath('data.outlet_id', $this->kemang->id);

        $data = $this->getJson('/api/v1/pos/catalog', bearer($this->posToken))->json('data');
        expect(collect($data['items'])->firstWhere('id', $this->croissant->id)['sold_out'])->toBeTrue();

        // Token perangkat tanpa login kasir tidak boleh.
        $this->postJson("/api/v1/pos/items/{$this->croissant->id}/sold-out", ['sold_out' => false], bearer($this->deviceToken))->assertForbidden();

        // Menu company lain → 404.
        [$other] = Factory::company('Warung Bu Ratna');
        $foreign = Menu::item($other, Factory::brand($other));
        $this->postJson("/api/v1/pos/items/{$foreign->id}/sold-out", ['sold_out' => true], bearer($this->posToken))->assertNotFound();
    });
});
