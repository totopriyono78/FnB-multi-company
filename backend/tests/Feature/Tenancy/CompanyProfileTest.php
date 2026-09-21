<?php

use Tests\Support\Factory;

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
});

it('menampilkan dan mengubah profil company (FR-TEN-03)', function () {
    $headers = asMember($this->owner, $this->company);

    $this->getJson('/api/v1/company', $headers)
        ->assertOk()
        ->assertJsonPath('data.name', 'Kopi Tepi Jalan')
        ->assertJsonPath('data.currency', 'IDR')
        ->assertJsonPath('data.subscription.plan.code', 'pro');

    $this->patchJson('/api/v1/company', [
        'legal_name' => 'PT Kopi Nusantara Sejahtera',
        'npwp' => '01.234.567.8-901.000',
        'city' => 'Jakarta Selatan',
        'postal_code' => '12730',
        'timezone' => 'Asia/Makassar',
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.legal_name', 'PT Kopi Nusantara Sejahtera')
        ->assertJsonPath('data.timezone', 'Asia/Makassar');
});

it('memvalidasi NPWP, zona waktu, dan mata uang', function () {
    $response = $this->patchJson('/api/v1/company', [
        'npwp' => '123', 'timezone' => 'Europe/London', 'currency' => 'USD',
    ], asMember($this->owner, $this->company));

    $response->assertUnprocessable();
    expect(collect($response->json('errors'))->pluck('field')->all())->toContain('npwp', 'timezone', 'currency')
        ->and(collect($response->json('errors'))->firstWhere('field', 'npwp')['message'])->toBe('Format NPWP tidak valid (15/16 digit).');
});

it('membatasi akses profil sesuai role', function (string $role, int $view, int $update) {
    $outlet = Factory::outlet($this->company);
    [$user] = Factory::staff($this->company, [$role], [$outlet->id]);
    $headers = asMember($user, $this->company);

    $this->getJson('/api/v1/company', $headers)->assertStatus($view);
    $this->patchJson('/api/v1/company', ['city' => 'Bogor'], $headers)->assertStatus($update);
})->with([
    ['finance', 200, 403],
    ['cashier', 403, 403],
    ['outlet_manager', 403, 403],
]);
