<?php

use App\Modules\Tenancy\Domain\Models\DevicePairingCode;
use Illuminate\Support\Facades\DB;
use Tests\Support\Factory;

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan', 'basic');
    $this->outlet = Factory::outlet($this->company, null, ['code' => 'KMG']);
});

it('mendaftarkan perangkat dan menerbitkan kode pairing (FR-DEV-01)', function () {
    $response = $this->postJson('/api/v1/devices', [
        'outlet_id' => $this->outlet->id, 'code' => 'pos-01', 'name' => 'Kasir depan', 'type' => 'pos',
    ], asMember($this->owner, $this->company));

    $response->assertCreated()
        ->assertJsonPath('data.device.code', 'POS01')
        ->assertJsonPath('data.device.status', 'pending')
        ->assertJsonPath('data.device.is_online', false);

    expect($response->json('data.pairing.code'))->toMatch('/^[A-HJKMNP-Z2-9]{8}$/');

    // Kode disimpan sebagai hash, bukan teks asli.
    $stored = Factory::tenant($this->company, fn () => DevicePairingCode::query()->first());
    expect($stored->code_hash)->not->toBe($response->json('data.pairing.code'));
});

it('memasangkan perangkat dengan kode sekali pakai', function () {
    $create = $this->postJson('/api/v1/devices', [
        'outlet_id' => $this->outlet->id, 'code' => 'POS01', 'name' => 'Kasir', 'type' => 'pos',
    ], asMember($this->owner, $this->company))->assertCreated();
    $code = $create->json('data.pairing.code');

    $pair = $this->postJson('/api/v1/devices/pair', ['code' => strtolower(chunk_split($code, 4, '-')), 'platform' => 'android', 'app_version' => '1.0.0']);

    $pair->assertOk()
        ->assertJsonPath('data.company_id', $this->company->id)
        ->assertJsonPath('data.device.status', 'active')
        ->assertJsonPath('data.outlet.code', 'KMG');
    expect($pair->json('data.token'))->toBeString();

    // Kode tidak bisa dipakai dua kali.
    $this->postJson('/api/v1/devices/pair', ['code' => $code])->assertUnprocessable();

    // Token device dapat dipakai untuk heartbeat.
    $this->postJson('/api/v1/devices/heartbeat', ['pending_sync_count' => 3, 'app_version' => '1.0.1'], bearer($pair->json('data.token')))
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.wipe', false);

    $show = $this->getJson('/api/v1/devices/'.$create->json('data.device.id'), asMember($this->owner, $this->company))->assertOk();
    expect($show->json('data.pending_sync_count'))->toBe(3)
        ->and($show->json('data.app_version'))->toBe('1.0.1')
        ->and($show->json('data.is_online'))->toBeTrue();
});

it('menolak kode pairing kedaluwarsa atau salah', function () {
    $device = Factory::device($this->company, $this->outlet);
    $code = $this->postJson("/api/v1/devices/{$device->id}/pairing-code", [], asMember($this->owner, $this->company))->json('data.code');

    $this->travel(16)->minutes();
    $this->postJson('/api/v1/devices/pair', ['code' => $code])->assertUnprocessable();
    $this->postJson('/api/v1/devices/pair', ['code' => 'ABCDEFGH'])->assertUnprocessable();
});

it('membatalkan kode lama saat kode baru diterbitkan', function () {
    $device = Factory::device($this->company, $this->outlet);
    $headers = asMember($this->owner, $this->company);
    $first = $this->postJson("/api/v1/devices/{$device->id}/pairing-code", [], $headers)->json('data.code');
    $second = $this->postJson("/api/v1/devices/{$device->id}/pairing-code", [], $headers)->json('data.code');

    $this->postJson('/api/v1/devices/pair', ['code' => $first])->assertUnprocessable();
    $this->postJson('/api/v1/devices/pair', ['code' => $second])->assertOk();
});

it('menonaktifkan perangkat dari jarak jauh (FR-DEV-07)', function () {
    [$device, $deviceToken] = Factory::pairedDevice($this->company, $this->outlet);
    [, $member] = Factory::staff($this->company, ['cashier'], [$this->outlet->id], '4826');
    $posToken = $this->postJson('/api/v1/pos/auth/pin', ['staff_id' => $member->id, 'pin' => '4826'], bearer($deviceToken))->assertOk()->json('data.token');

    $this->postJson("/api/v1/devices/{$device->id}/revoke", ['reason' => 'Tablet hilang'], asMember($this->owner, $this->company))
        ->assertOk()
        ->assertJsonPath('data.status', 'revoked');

    $revoked = $this->postJson('/api/v1/devices/heartbeat', ['pending_sync_count' => 0], bearer($deviceToken));
    $revoked->assertForbidden();
    expect(firstErrorCode($revoked))->toBe('DEVICE_REVOKED');

    // Token kasir yang terikat ke perangkat ikut dicabut.
    $this->postJson('/api/v1/pos/auth/logout', [], bearer($posToken))->assertUnauthorized();

    // Alasan wajib diisi.
    $this->postJson("/api/v1/devices/{$device->id}/revoke", [], asMember($this->owner, $this->company))->assertUnprocessable();
});

it('menegakkan batas perangkat paket (FR-TEN-06)', function () {
    foreach (range(1, 4) as $i) {
        Factory::device($this->company, $this->outlet, ['code' => "POS0{$i}"]);
    }

    $this->postJson('/api/v1/devices', [
        'outlet_id' => $this->outlet->id, 'code' => 'POS05', 'name' => 'Kasir', 'type' => 'pos',
    ], asMember($this->owner, $this->company))->assertStatus(402);
});

it('menolak kode perangkat ganda di outlet yang sama', function () {
    Factory::device($this->company, $this->outlet, ['code' => 'POS01']);

    $this->postJson('/api/v1/devices', [
        'outlet_id' => $this->outlet->id, 'code' => 'POS01', 'name' => 'Kasir', 'type' => 'pos',
    ], asMember($this->owner, $this->company))->assertUnprocessable();
});

it('memisahkan jenis token: token back-office tidak bisa memanggil endpoint perangkat, dan sebaliknya', function () {
    [, $deviceToken] = Factory::pairedDevice($this->company, $this->outlet);

    $wrong = $this->postJson('/api/v1/devices/heartbeat', ['pending_sync_count' => 0], asMember($this->owner, $this->company));
    $wrong->assertForbidden();
    expect(firstErrorCode($wrong))->toBe('WRONG_CLIENT');

    $this->getJson('/api/v1/brands', bearer($deviceToken))->assertForbidden();
    $this->getJson('/api/v1/auth/me', bearer($deviceToken))->assertForbidden();
});

it('menampilkan perangkat hanya untuk outlet yang dikelola', function () {
    $dago = Factory::outlet($this->company, null, ['code' => 'DGO']);
    Factory::device($this->company, $this->outlet, ['code' => 'POS01']);
    $foreign = Factory::device($this->company, $dago, ['code' => 'POS09']);
    [$manager] = Factory::staff($this->company, ['outlet_manager'], [$this->outlet->id]);
    $headers = asMember($manager, $this->company);

    $list = $this->getJson('/api/v1/devices', $headers)->assertOk();
    expect(collect($list->json('data'))->pluck('code')->all())->toBe(['POS01']);
    $this->getJson("/api/v1/devices/{$foreign->id}", $headers)->assertForbidden();
    // Manajer outlet hanya boleh melihat perangkat.
    $this->postJson('/api/v1/devices', ['outlet_id' => $this->outlet->id, 'code' => 'POS03', 'name' => 'X', 'type' => 'pos'], $headers)->assertForbidden();
});

it('tidak menyimpan kode pairing dalam audit log', function () {
    $response = $this->postJson('/api/v1/devices', [
        'outlet_id' => $this->outlet->id, 'code' => 'POS01', 'name' => 'Kasir', 'type' => 'pos',
    ], asMember($this->owner, $this->company));
    $code = $response->json('data.pairing.code');

    $found = Factory::system(fn () => DB::table('audit_logs')
        ->whereRaw('coalesce(new_values::text, \'\') like ?', ['%'.$code.'%'])
        ->orWhereRaw('coalesce(metadata::text, \'\') like ?', ['%'.$code.'%'])
        ->exists());

    expect($found)->toBeFalse();
});

it('mengubah nama perangkat dan menampilkan profil perangkat untuk aplikasi', function () {
    [$device, $token] = Factory::pairedDevice($this->company, $this->outlet);

    $this->patchJson("/api/v1/devices/{$device->id}", ['name' => 'Kasir teras'], asMember($this->owner, $this->company))
        ->assertOk()->assertJsonPath('data.name', 'Kasir teras');
    $this->patchJson("/api/v1/devices/{$device->id}", ['outlet_id' => $this->outlet->id], asMember($this->owner, $this->company))
        ->assertUnprocessable();

    $this->getJson('/api/v1/devices/me', bearer($token))
        ->assertOk()
        ->assertJsonPath('data.id', $device->id)
        ->assertJsonPath('data.outlet.code', 'KMG');
});
