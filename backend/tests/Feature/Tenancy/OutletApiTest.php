<?php

use Tests\Support\Factory;

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan', 'basic');
    $this->brand = Factory::brand($this->company, ['code' => 'KTJ']);
});

function outletPayload(string $brandId, array $overrides = []): array
{
    return $overrides + [
        'brand_id' => $brandId,
        'code' => 'kmg',
        'name' => 'Kopi Tepi Jalan Kemang',
        'address' => 'Jl. Kemang Raya No. 18',
        'city' => 'Jakarta Selatan',
        'postal_code' => '12730',
        'tax_rate' => '10',
        'tax_inclusive' => false,
        'service_charge_rate' => '5',
        'rounding_unit' => 100,
        'order_mode' => 'dine_in',
        'business_day_cutoff' => '03:00',
        'opening_hours' => ['mon' => ['open' => '07:00', 'close' => '22:00']],
    ];
}

it('membuat outlet beserta konfigurasi pajak & operasional (FR-TEN-05)', function () {
    $response = $this->postJson('/api/v1/outlets', outletPayload($this->brand->id), asMember($this->owner, $this->company));

    $response->assertCreated()
        ->assertJsonPath('data.code', 'KMG')
        ->assertJsonPath('data.brand.code', 'KTJ')
        ->assertJsonPath('data.tax.rate', '10.00')
        ->assertJsonPath('data.tax.inclusive', false)
        ->assertJsonPath('data.service_charge_rate', '5.00')
        ->assertJsonPath('data.rounding.unit', 100)
        ->assertJsonPath('data.order_mode', 'dine_in')
        ->assertJsonPath('data.business_day_cutoff', '03:00')
        ->assertJsonPath('data.timezone', 'Asia/Jakarta');
});

it('memvalidasi konfigurasi outlet', function (array $override, string $field) {
    $response = $this->postJson('/api/v1/outlets', outletPayload($this->brand->id, $override), asMember($this->owner, $this->company));

    $response->assertUnprocessable();
    expect(collect($response->json('errors'))->pluck('field')->all())->toContain($field);
})->with([
    'tarif pajak > 100' => [['tax_rate' => '150'], 'tax_rate'],
    'tarif pajak 3 desimal' => [['tax_rate' => '10.125'], 'tax_rate'],
    'pembulatan tidak dikenal' => [['rounding_unit' => 250], 'rounding_unit'],
    'mode order tidak dikenal' => [['order_mode' => 'drive_thru'], 'order_mode'],
    'kode pos salah' => [['postal_code' => '12'], 'postal_code'],
    'jam tidak valid' => [['business_day_cutoff' => '25:00'], 'business_day_cutoff'],
    'kode dengan simbol' => [['code' => 'KM-G'], 'code'],
]);

it('menolak brand dari company lain saat membuat outlet', function () {
    [$other] = Factory::company('Warung Bu Ratna');
    $foreignBrand = Factory::brand($other);

    $response = $this->postJson('/api/v1/outlets', outletPayload($foreignBrand->id), asMember($this->owner, $this->company));

    $response->assertUnprocessable();
    expect($response->json('errors.0.field'))->toBe('brand_id');
});

it('menegakkan batas jumlah outlet paket (FR-TEN-06)', function () {
    Factory::outlet($this->company, $this->brand, ['code' => 'A1']);
    Factory::outlet($this->company, $this->brand, ['code' => 'A2']);

    $response = $this->postJson('/api/v1/outlets', outletPayload($this->brand->id), asMember($this->owner, $this->company));

    $response->assertStatus(402);
    expect($response->json('errors.0.field'))->toBe('plan');
});

it('membatasi manajer outlet pada outlet yang dikelola (FR-AUTH-06)', function () {
    $kemang = Factory::outlet($this->company, $this->brand, ['code' => 'KMG', 'name' => 'Kemang']);
    $dago = Factory::outlet($this->company, $this->brand, ['code' => 'DGO', 'name' => 'Dago']);
    [$manager] = Factory::staff($this->company, ['outlet_manager'], [$kemang->id]);
    $headers = asMember($manager, $this->company);

    $list = $this->getJson('/api/v1/outlets', $headers)->assertOk();
    expect(collect($list->json('data'))->pluck('code')->all())->toBe(['KMG']);

    $this->getJson("/api/v1/outlets/{$kemang->id}", $headers)->assertOk();
    $this->getJson("/api/v1/outlets/{$dago->id}", $headers)->assertForbidden();
    // Manajer outlet hanya melihat (Lampiran 12.1).
    $this->patchJson("/api/v1/outlets/{$kemang->id}", ['name' => 'Ubah'], $headers)->assertForbidden();
    $this->postJson('/api/v1/outlets', outletPayload($this->brand->id, ['code' => 'BARU']), $headers)->assertForbidden();
});

it('mengubah dan menonaktifkan outlet', function () {
    $outlet = Factory::outlet($this->company, $this->brand);
    $headers = asMember($this->owner, $this->company);

    $this->patchJson("/api/v1/outlets/{$outlet->id}", ['tax_rate' => '11', 'tax_inclusive' => true], $headers)
        ->assertOk()
        ->assertJsonPath('data.tax.rate', '11.00')
        ->assertJsonPath('data.tax.inclusive', true);

    $this->deleteJson("/api/v1/outlets/{$outlet->id}", [], $headers)->assertOk();
    $this->getJson('/api/v1/outlets', $headers)->assertJsonCount(0, 'data');
});
