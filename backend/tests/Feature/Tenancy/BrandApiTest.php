<?php

use App\Modules\Audit\Domain\AuditLog;
use Tests\Support\Factory;

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
});

it('membuat brand dengan respons standar dan audit log (FR-TEN-04)', function () {
    $response = $this->postJson('/api/v1/brands', ['code' => 'ktj', 'name' => 'Kopi Tepi Jalan'], asMember($this->owner, $this->company));

    $response->assertCreated()
        ->assertJsonPath('data.code', 'KTJ')
        ->assertJsonPath('data.name', 'Kopi Tepi Jalan')
        ->assertJsonPath('data.is_active', true)
        ->assertHeader('X-Request-Id');
    assertStandardEnvelope($response);

    $log = Factory::tenant($this->company, fn () => AuditLog::query()->where('action', 'brand.created')->first());
    expect($log->user_id)->toBe($this->owner->id)
        ->and($log->new_values['code'])->toBe('KTJ');
});

it('memvalidasi input brand', function () {
    Factory::brand($this->company, ['code' => 'KTJ']);

    $response = $this->postJson('/api/v1/brands', ['code' => 'ktj', 'name' => ''], asMember($this->owner, $this->company));

    $response->assertUnprocessable();
    assertStandardEnvelope($response, false);
    expect(collect($response->json('errors'))->pluck('field')->all())->toContain('code', 'name');
});

it('mengizinkan kode brand yang sama di company berbeda', function () {
    [$other] = Factory::company('Roti Bakar 88');
    Factory::brand($other, ['code' => 'KTJ']);

    $this->postJson('/api/v1/brands', ['code' => 'KTJ', 'name' => 'Kopi Tepi Jalan'], asMember($this->owner, $this->company))
        ->assertCreated();
});

it('mengubah, mencari, dan menonaktifkan brand', function () {
    $brand = Factory::brand($this->company, ['code' => 'RB', 'name' => 'Roti Bakar']);
    $headers = asMember($this->owner, $this->company);

    $this->patchJson("/api/v1/brands/{$brand->id}", ['name' => 'Roti Bakar 88'], $headers)
        ->assertOk()->assertJsonPath('data.name', 'Roti Bakar 88');

    $this->getJson('/api/v1/brands?search=88', $headers)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/brands?search=soto', $headers)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/brands?search='.urlencode('50%_\\'), $headers)->assertOk()->assertJsonCount(0, 'data');

    $this->deleteJson("/api/v1/brands/{$brand->id}", [], $headers)->assertOk();
    $this->getJson("/api/v1/brands/{$brand->id}", $headers)->assertNotFound();
});

it('menolak menghapus brand yang masih punya outlet aktif', function () {
    $brand = Factory::brand($this->company);
    Factory::outlet($this->company, $brand);

    $response = $this->deleteJson("/api/v1/brands/{$brand->id}", [], asMember($this->owner, $this->company));

    $response->assertStatus(409);
    expect(firstErrorCode($response))->toBe('BRAND_HAS_ACTIVE_OUTLETS');
});

it('menerapkan hak akses brand sesuai matriks role (SRS §12.1)', function (string $role, int $list, int $create) {
    $outlet = Factory::outlet($this->company);
    [$user] = Factory::staff($this->company, [$role], in_array($role, ['cashier', 'outlet_manager'], true) ? [$outlet->id] : []);
    $headers = asMember($user, $this->company);

    $this->getJson('/api/v1/brands', $headers)->assertStatus($list);
    $this->postJson('/api/v1/brands', ['code' => 'BARU', 'name' => 'Brand Baru'], $headers)->assertStatus($create);
})->with([
    'admin company' => ['company_admin', 200, 201],
    'manajer brand' => ['brand_manager', 200, 403],
    'manajer outlet' => ['outlet_manager', 200, 403],
    'kasir' => ['cashier', 403, 403],
    'dapur' => ['kitchen', 403, 403],
    'finance' => ['finance', 403, 403],
]);

it('membatasi manajer brand hanya pada brand yang dipegang (FR-AUTH-06)', function () {
    $mine = Factory::brand($this->company, ['code' => 'KTJ']);
    $other = Factory::brand($this->company, ['code' => 'RB88']);
    [$manager] = Factory::staff($this->company, ['brand_manager'], [], null, [$mine->id]);
    $headers = asMember($manager, $this->company);

    $response = $this->getJson('/api/v1/brands', $headers)->assertOk();
    expect(collect($response->json('data'))->pluck('code')->all())->toBe(['KTJ']);

    $this->getJson("/api/v1/brands/{$mine->id}", $headers)->assertOk();
    $this->getJson("/api/v1/brands/{$other->id}", $headers)->assertForbidden();
});

it('menolak request tanpa token', function () {
    $response = $this->getJson('/api/v1/brands', ['X-Company-Id' => $this->company->id]);

    $response->assertUnauthorized();
    expect(firstErrorCode($response))->toBe('UNAUTHENTICATED');
});
