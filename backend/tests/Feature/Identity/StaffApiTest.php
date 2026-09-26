<?php

use App\Modules\Identity\Application\Notifications\CompanyInvitation;
use App\Modules\Identity\Application\StaffManager;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Factory;

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan', 'basic');
    $this->kemang = Factory::outlet($this->company, null, ['code' => 'KMG']);
    $this->dago = Factory::outlet($this->company, null, ['code' => 'DGO']);
});

it('menambah staf baru dengan role, cakupan, dan PIN (FR-AUTH-05/06)', function () {
    Notification::fake();

    $response = $this->postJson('/api/v1/staff', [
        'name' => 'Andi Saputra',
        'email' => 'Andi@Kopi.test',
        'phone' => '0812 7777 8888',
        'employee_code' => 'KMG-002',
        'roles' => ['cashier'],
        'scopes' => ['outlets' => [$this->kemang->id]],
        'pin' => '7351',
    ], asMember($this->owner, $this->company));

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Andi Saputra')
        ->assertJsonPath('data.email', 'andi@kopi.test')
        ->assertJsonPath('data.phone', '6281277778888')
        ->assertJsonPath('data.roles', ['cashier'])
        ->assertJsonPath('data.scopes.outlets', [$this->kemang->id])
        ->assertJsonPath('data.has_pin', true);
    expect($response->json('data'))->not->toHaveKey('pin_hash');

    $user = Factory::system(fn () => User::query()->where('email', 'andi@kopi.test')->firstOrFail());
    Notification::assertSentTo($user, ResetPassword::class);
});

it('mengundang akun yang sudah ada dan baru aktif setelah diterima (FR-AUTH-04)', function () {
    Notification::fake();
    [$other] = Factory::company('Warung Bu Ratna');
    [$existing] = Factory::staff($other, ['cashier']);
    $originalName = $existing->name;

    $invite = $this->postJson('/api/v1/staff', [
        'name' => 'Nama Lain', 'email' => $existing->email, 'roles' => ['finance'],
    ], asMember($this->owner, $this->company))->assertCreated()
        ->assertJsonPath('data.user_id', $existing->id)
        ->assertJsonPath('data.is_active', false);

    Notification::assertSentTo($existing, CompanyInvitation::class);
    // Nama global akun tidak diubah oleh company yang mengundang.
    expect(Factory::system(fn () => $existing->fresh()->name))->toBe($originalName);

    // Belum bisa mengakses company sebelum menerima undangan.
    $this->getJson('/api/v1/company', asMember($existing, $this->company))->assertForbidden();
    $this->patchJson('/api/v1/staff/'.$invite->json('data.id'), ['is_active' => true], asMember($this->owner, $this->company))
        ->assertUnprocessable();

    $pending = $this->getJson('/api/v1/auth/invitations', asMember($existing))->assertOk();
    expect($pending->json('data.0.company_name'))->toBe('Kopi Tepi Jalan');

    $this->postJson('/api/v1/auth/invitations/'.$pending->json('data.0.id').'/accept', [], asMember($existing))->assertOk();

    $me = $this->getJson('/api/v1/auth/me', asMember($existing))->assertOk();
    expect($me->json('data.companies'))->toHaveCount(2);
    $this->getJson('/api/v1/company', asMember($existing, $this->company))->assertOk();
    // Role berbeda per company: finance tidak mengelola staf.
    $this->getJson('/api/v1/staff', asMember($existing, $this->company))->assertForbidden();
});

it('menolak undangan dan tidak menyisakan akses', function () {
    [$other] = Factory::company('Warung Bu Ratna');
    [$existing] = Factory::staff($other, ['cashier']);
    $this->postJson('/api/v1/staff', ['name' => 'X', 'email' => $existing->email, 'roles' => ['finance']], asMember($this->owner, $this->company))->assertCreated();
    $id = $this->getJson('/api/v1/auth/invitations', asMember($existing))->json('data.0.id');

    // User lain tidak bisa menerima undangan milik orang lain.
    $this->postJson("/api/v1/auth/invitations/{$id}/accept", [], asMember($this->owner))->assertNotFound();

    $this->postJson("/api/v1/auth/invitations/{$id}/decline", [], asMember($existing))->assertOk();
    $this->getJson('/api/v1/auth/invitations', asMember($existing))->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/company', asMember($existing, $this->company))->assertForbidden();
});

it('tidak mengubah nama akun yang juga dipakai di company lain', function () {
    [$other] = Factory::company('Warung Bu Ratna');
    [$existing, $foreignMember] = Factory::staff($other, ['cashier']);
    $member = Factory::tenant($this->company, fn () => app(StaffManager::class)
        ->create($this->owner, ['name' => 'x', 'email' => $existing->email, 'roles' => ['kitchen']]));

    $this->patchJson("/api/v1/staff/{$member->id}", ['name' => 'Nama Palsu'], asMember($this->owner, $this->company))
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'name');
});

it('menolak email yang sudah menjadi anggota dan PIN yang terlalu mudah', function () {
    [$andi] = Factory::staff($this->company, ['cashier'], [$this->kemang->id], '7351');
    $headers = asMember($this->owner, $this->company);

    $this->postJson('/api/v1/staff', ['name' => 'X', 'email' => $andi->email, 'roles' => ['cashier']], $headers)
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'email');

    foreach (['1234', '0000', '777777'] as $weak) {
        $this->postJson('/api/v1/staff', ['name' => 'Siti', 'email' => "siti{$weak}@kopi.test", 'roles' => ['cashier'], 'scopes' => ['outlets' => [$this->kemang->id]], 'pin' => $weak], $headers)
            ->assertUnprocessable()->assertJsonPath('errors.0.field', 'pin');
    }

    // PIN yang sama dengan staf lain diperbolehkan dan tidak membocorkan informasi.
    $this->postJson('/api/v1/staff', ['name' => 'Siti', 'email' => 'siti@kopi.test', 'roles' => ['cashier'], 'scopes' => ['outlets' => [$this->kemang->id]], 'pin' => '7351'], $headers)
        ->assertCreated();
});

it('membatasi manajer outlet hanya mengelola kasir/dapur di outletnya', function () {
    [$manager] = Factory::staff($this->company, ['outlet_manager'], [$this->kemang->id]);
    $headers = asMember($manager, $this->company);

    $this->postJson('/api/v1/staff', [
        'name' => 'Siti', 'email' => 'siti@kopi.test', 'roles' => ['cashier'], 'scopes' => ['outlets' => [$this->kemang->id]],
    ], $headers)->assertCreated();

    $this->postJson('/api/v1/staff', [
        'name' => 'Admin', 'email' => 'admin@kopi.test', 'roles' => ['company_admin'], 'scopes' => ['outlets' => [$this->kemang->id]],
    ], $headers)->assertForbidden();

    $this->postJson('/api/v1/staff', [
        'name' => 'Putri', 'email' => 'putri@kopi.test', 'roles' => ['cashier'], 'scopes' => ['outlets' => [$this->dago->id]],
    ], $headers)->assertForbidden();

    $this->postJson('/api/v1/staff', [
        'name' => 'Tanpa outlet', 'email' => 'bebas@kopi.test', 'roles' => ['cashier'],
    ], $headers)->assertForbidden();

    // Daftar hanya berisi staf outlet Kemang.
    [, $dagoCashier] = Factory::staff($this->company, ['cashier'], [$this->dago->id]);
    $list = $this->getJson('/api/v1/staff', $headers)->assertOk();
    expect(collect($list->json('data'))->pluck('id'))->not->toContain($dagoCashier->id);
    $this->getJson("/api/v1/staff/{$dagoCashier->id}", $headers)->assertForbidden();
});

it('melindungi akun pemilik dari perubahan oleh admin', function () {
    [$admin] = Factory::staff($this->company, ['company_admin']);
    $ownerMember = Factory::tenant($this->company, fn () => CompanyUser::query()->where('user_id', $this->owner->id)->firstOrFail());

    $this->patchJson("/api/v1/staff/{$ownerMember->id}", ['is_active' => false], asMember($admin, $this->company))->assertForbidden();
    $this->postJson('/api/v1/staff', ['name' => 'Pemilik 2', 'email' => 'p2@kopi.test', 'roles' => ['owner']], asMember($admin, $this->company))
        ->assertForbidden();
});

it('mengubah role & menonaktifkan staf sekaligus menutup aksesnya (FR-AUTH-09)', function () {
    [$staff, $member] = Factory::staff($this->company, ['cashier'], [$this->kemang->id]);
    $staffToken = Factory::token($staff);
    $headers = asMember($this->owner, $this->company);

    $this->patchJson("/api/v1/staff/{$member->id}", ['roles' => ['outlet_manager']], $headers)
        ->assertOk()->assertJsonPath('data.roles', ['outlet_manager'])
        ->assertJsonPath('data.scopes.outlets', [$this->kemang->id]);

    $this->patchJson("/api/v1/staff/{$member->id}", ['is_active' => false], $headers)
        ->assertOk()->assertJsonPath('data.is_active', false);

    $this->getJson('/api/v1/outlets', bearer($staffToken) + ['X-Company-Id' => $this->company->id])
        ->assertForbidden()->assertJsonPath('errors.0.code', 'NOT_A_MEMBER');
});

it('mencabut sesi hanya untuk company ini tanpa mengganggu company lain milik user', function () {
    [$other, $otherOwner] = Factory::company('Warung Bu Ratna');
    [$staff, $member] = Factory::staff($this->company, ['outlet_manager'], [$this->kemang->id]);
    Factory::tenant($other, fn () => app(StaffManager::class)->create($otherOwner, [
        'name' => $staff->name, 'email' => $staff->email, 'roles' => ['finance'],
    ]));
    Factory::system(fn () => CompanyUser::query()->where('company_id', $other->id)->where('user_id', $staff->id)->update(['accepted_at' => now(), 'is_active' => true]));
    $generalToken = Factory::token($staff);
    $this->travel(2)->seconds();

    $this->postJson("/api/v1/staff/{$member->id}/revoke-sessions", [], asMember($this->owner, $this->company))->assertOk();

    $this->getJson('/api/v1/outlets', bearer($generalToken) + ['X-Company-Id' => $this->company->id])
        ->assertUnauthorized()->assertJsonPath('errors.0.code', 'SESSION_REVOKED');
    $this->getJson('/api/v1/company', bearer($generalToken) + ['X-Company-Id' => $other->id])->assertOk();

    // Login baru setelah pencabutan kembali berlaku.
    $this->travel(2)->seconds();
    $this->getJson('/api/v1/outlets', asMember($staff, $this->company))->assertOk();
});

it('mengganti PIN dengan konfirmasi', function () {
    [, $member] = Factory::staff($this->company, ['cashier'], [$this->kemang->id]);
    $headers = asMember($this->owner, $this->company);

    $this->putJson("/api/v1/staff/{$member->id}/pin", ['pin' => '9024', 'pin_confirmation' => '9025'], $headers)->assertUnprocessable();
    $this->putJson("/api/v1/staff/{$member->id}/pin", ['pin' => '90a4', 'pin_confirmation' => '90a4'], $headers)->assertUnprocessable();
    $this->putJson("/api/v1/staff/{$member->id}/pin", ['pin' => '9024', 'pin_confirmation' => '9024'], $headers)
        ->assertOk()->assertJsonPath('data.has_pin', true);
});

it('tetap bisa menyunting staf aktif yang undangannya tidak pernah tercatat diterima', function () {
    // Keadaan yang bisa muncul dari data contoh/impor: anggota sudah aktif, tetapi jejak
    // undangannya belum ditandai diterima. Penjagaan "undangan belum diterima" hanya boleh
    // menghalangi *pengaktifan*, bukan setiap penyimpanan — kalau tidak, ganti PIN dari
    // back-office ditolak dengan pesan yang tidak nyambung dan kasir tak bisa masuk.
    [, $member] = Factory::staff($this->company, ['cashier'], [$this->kemang->id]);
    Factory::system(fn () => CompanyUser::query()->whereKey($member->id)
        ->update(['is_active' => true, 'invited_at' => now(), 'accepted_at' => null]));

    $this->patchJson("/api/v1/staff/{$member->id}", [
        'employee_code' => 'KMG-009', 'is_active' => true, 'pin' => '551122',
    ], asMember($this->owner, $this->company))->assertOk();

    $segar = Factory::system(fn () => CompanyUser::query()->findOrFail($member->id));
    expect(Hash::check('551122', (string) $segar->pin_hash))->toBeTrue();
});

it('menegakkan batas user paket (FR-TEN-06)', function () {
    foreach (range(1, 14) as $i) {
        Factory::staff($this->company, ['kitchen']);
    }

    $this->postJson('/api/v1/staff', ['name' => 'Kelebihan', 'email' => 'lebih@kopi.test', 'roles' => ['kitchen']], asMember($this->owner, $this->company))
        ->assertStatus(402);
});

it('menolak akses kelola staf untuk kasir', function () {
    [$cashier] = Factory::staff($this->company, ['cashier'], [$this->kemang->id]);

    $this->getJson('/api/v1/staff', asMember($cashier, $this->company))->assertForbidden();
    $this->postJson('/api/v1/staff', ['name' => 'X', 'email' => 'x@kopi.test', 'roles' => ['cashier']], asMember($cashier, $this->company))->assertForbidden();
});
