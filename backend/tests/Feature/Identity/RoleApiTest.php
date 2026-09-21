<?php

use Tests\Support\Factory;

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
});

it('menampilkan katalog permission terkelompok', function () {
    $response = $this->getJson('/api/v1/permissions', asMember($this->owner, $this->company))->assertOk();

    expect($response->json('data'))->toHaveKeys(['company', 'organization', 'pos', 'audit'])
        ->and($response->json('data.pos.0'))->toHaveKeys(['name', 'label']);
});

it('membuat, mengubah, dan menghapus role kustom (FR-AUTH-05)', function () {
    $headers = asMember($this->owner, $this->company);

    $created = $this->postJson('/api/v1/roles', [
        'name' => 'kasir_senior',
        'label' => 'Kasir Senior',
        'max_discount_percent' => '10',
        'permissions' => ['pos.transact', 'pos.shift', 'pos.discount'],
    ], $headers)->assertCreated()
        ->assertJsonPath('data.is_system', false)
        ->assertJsonPath('data.permissions', ['pos.discount', 'pos.shift', 'pos.transact']);

    $id = $created->json('data.id');
    $this->getJson("/api/v1/roles/{$id}", $headers)->assertOk()->assertJsonPath('data.label', 'Kasir Senior');

    $this->patchJson("/api/v1/roles/{$id}", ['permissions' => ['pos.transact']], $headers)
        ->assertOk()->assertJsonPath('data.permissions', ['pos.transact']);

    // Role kustom langsung dapat dipakai untuk staf.
    $outlet = Factory::outlet($this->company);
    $this->postJson('/api/v1/staff', ['name' => 'Joko', 'email' => 'joko@kopi.test', 'roles' => ['kasir_senior'], 'scopes' => ['outlets' => [$outlet->id]]], $headers)
        ->assertCreated();

    $this->deleteJson("/api/v1/roles/{$id}", [], $headers)->assertStatus(409)->assertJsonPath('errors.0.code', 'ROLE_IN_USE');
});

it('menolak permission yang tidak dikenal dan nama ganda', function () {
    $headers = asMember($this->owner, $this->company);

    $this->postJson('/api/v1/roles', ['name' => 'cashier', 'label' => 'Kasir', 'permissions' => []], $headers)->assertUnprocessable();
    $this->postJson('/api/v1/roles', ['name' => 'root', 'label' => 'Root', 'permissions' => ['system.everything']], $headers)->assertUnprocessable();
    $this->postJson('/api/v1/roles', ['name' => 'Kasir Senior', 'label' => 'X', 'permissions' => []], $headers)->assertUnprocessable();
});

it('mengunci role bawaan', function () {
    $headers = asMember($this->owner, $this->company);
    $roles = collect($this->getJson('/api/v1/roles', $headers)->json('data'));

    $this->deleteJson('/api/v1/roles/'.$roles->firstWhere('name', 'cashier')['id'], [], $headers)->assertStatus(409);
    $this->patchJson('/api/v1/roles/'.$roles->firstWhere('name', 'owner')['id'], ['permissions' => []], $headers)->assertStatus(409);

    // Role bawaan non-pemilik dapat disesuaikan.
    $this->patchJson('/api/v1/roles/'.$roles->firstWhere('name', 'cashier')['id'], ['max_discount_percent' => '5'], $headers)
        ->assertOk()->assertJsonPath('data.max_discount_percent', '5.00');
});

it('hanya pemilik & admin yang dapat mengelola role', function (string $role, int $status) {
    [$user] = Factory::staff($this->company, [$role]);

    $this->getJson('/api/v1/roles', asMember($user, $this->company))->assertStatus($status);
})->with([
    ['company_admin', 200],
    ['finance', 403],
    ['brand_manager', 403],
    ['warehouse', 403],
]);

it('mencegah admin menaikkan hak aksesnya sendiri atau melebihi izinnya', function () {
    [$admin] = Factory::staff($this->company, ['company_admin']);
    $headers = asMember($admin, $this->company);
    $roles = collect($this->getJson('/api/v1/roles', $headers)->json('data'));

    // Role bawaan hanya boleh diubah pemilik.
    $this->patchJson('/api/v1/roles/'.$roles->firstWhere('name', 'cashier')['id'], ['permissions' => ['pos.transact', 'user.manage']], $headers)
        ->assertForbidden();
    $this->patchJson('/api/v1/roles/'.$roles->firstWhere('name', 'company_admin')['id'], ['permissions' => ['accounting.manage']], $headers)
        ->assertForbidden();

    // Izin yang tidak dimiliki admin tidak bisa diberikan lewat role kustom.
    $this->postJson('/api/v1/roles', ['name' => 'akuntan', 'label' => 'Akuntan', 'permissions' => ['accounting.manage']], $headers)
        ->assertForbidden();
    // Batas diskon tidak boleh melebihi batas admin (50%).
    $this->postJson('/api/v1/roles', ['name' => 'spv', 'label' => 'Supervisor', 'max_discount_percent' => '80', 'permissions' => ['pos.discount']], $headers)
        ->assertForbidden();
    $this->postJson('/api/v1/roles', ['name' => 'spv', 'label' => 'Supervisor', 'max_discount_percent' => '20', 'permissions' => ['pos.discount']], $headers)
        ->assertCreated();
});

it('mengizinkan admin menambah staf operasional tetapi tidak role dengan izin di luar kewenangannya', function () {
    [$admin] = Factory::staff($this->company, ['company_admin']);
    $outlet = Factory::outlet($this->company);
    $headers = asMember($admin, $this->company);

    foreach (['cashier', 'kitchen', 'outlet_manager', 'warehouse', 'brand_manager'] as $i => $role) {
        $this->postJson('/api/v1/staff', ['name' => "Staf {$i}", 'email' => "staf{$i}@kopi.test", 'roles' => [$role], 'scopes' => ['outlets' => [$outlet->id]]], $headers)
            ->assertCreated();
    }

    // Finance memegang akses akuntansi yang tidak dimiliki admin.
    $this->postJson('/api/v1/staff', ['name' => 'Lina', 'email' => 'lina@kopi.test', 'roles' => ['finance']], $headers)->assertForbidden();
});

it('mencegah manajer outlet memberi role kasir yang sudah diperluas pemilik', function () {
    $headers = asMember($this->owner, $this->company);
    $cashier = collect($this->getJson('/api/v1/roles', $headers)->json('data'))->firstWhere('name', 'cashier');
    $this->patchJson("/api/v1/roles/{$cashier['id']}", ['permissions' => ['pos.transact', 'user.manage']], $headers)->assertOk();

    $outlet = Factory::outlet($this->company);
    [$manager] = Factory::staff($this->company, ['outlet_manager'], [$outlet->id]);

    $this->postJson('/api/v1/staff', ['name' => 'Joko', 'email' => 'joko@kopi.test', 'roles' => ['cashier'], 'scopes' => ['outlets' => [$outlet->id]]], asMember($manager, $this->company))
        ->assertForbidden();
});

it('perubahan role di satu company tidak memengaruhi company lain', function () {
    [$other, $otherOwner] = Factory::company('Warung Bu Ratna');
    $headers = asMember($this->owner, $this->company);
    $cashierRole = collect($this->getJson('/api/v1/roles', $headers)->json('data'))->firstWhere('name', 'cashier');

    $this->patchJson("/api/v1/roles/{$cashierRole['id']}", ['permissions' => ['pos.transact', 'pos.void']], $headers)->assertOk();

    $otherCashier = collect($this->getJson('/api/v1/roles', asMember($otherOwner, $other))->json('data'))->firstWhere('name', 'cashier');
    expect($otherCashier['permissions'])->not->toContain('pos.void')
        ->and($otherCashier['id'])->not->toBe($cashierRole['id']);
});
