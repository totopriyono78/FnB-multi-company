<?php

use App\Modules\Audit\Domain\AuditLog;
use Tests\Support\Factory;

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->kemang = Factory::outlet($this->company, null, ['code' => 'KMG']);
    $this->dago = Factory::outlet($this->company, null, ['code' => 'DGO']);
    [$this->device, $this->deviceToken] = Factory::pairedDevice($this->company, $this->kemang);

    [$this->cashier, $this->cashierMember] = Factory::staff($this->company, ['cashier'], [$this->kemang->id], '7351');
    [$this->manager, $this->managerMember] = Factory::staff($this->company, ['outlet_manager'], [$this->kemang->id], '482915');
    [, $this->dagoCashier] = Factory::staff($this->company, ['cashier'], [$this->dago->id], '3867');
    [, $this->kitchen] = Factory::staff($this->company, ['kitchen'], [$this->kemang->id], '5566');
});

it('menampilkan hanya staf kasir yang terdaftar di outlet perangkat', function () {
    $response = $this->getJson('/api/v1/pos/staff', bearer($this->deviceToken))->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($this->cashierMember->id, $this->managerMember->id)
        ->not->toContain($this->dagoCashier->id, $this->kitchen->id);
    expect($response->json('data.0'))->toHaveKeys(['id', 'name', 'employee_code', 'locked'])
        ->not->toHaveKey('pin_hash');
});

it('login kasir dengan PIN di perangkat terdaftar (FR-AUTH-03)', function () {
    $response = $this->postJson('/api/v1/pos/auth/pin', ['staff_id' => $this->cashierMember->id, 'pin' => '7351'], bearer($this->deviceToken));

    $response->assertOk()
        ->assertJsonPath('data.staff.user_id', $this->cashier->id)
        ->assertJsonPath('data.staff.roles', ['cashier'])
        ->assertJsonPath('data.staff.max_discount_percent', '0.00');
    expect($response->json('data.staff.permissions'))->toContain('pos.transact')->not->toContain('pos.void');

    $posToken = $response->json('data.token');

    // Token POS tidak berlaku untuk back-office.
    $this->getJson('/api/v1/brands', bearer($posToken))->assertForbidden();
    $this->getJson('/api/v1/auth/me', bearer($posToken))->assertForbidden();

    $this->postJson('/api/v1/pos/auth/logout', [], bearer($posToken))->assertOk();

    $log = Factory::tenant($this->company, fn () => AuditLog::query()->where('action', 'pos.login')->first());
    expect($log->user_id)->toBe($this->cashier->id)->and($log->device_id)->toBe($this->device->id);
});

it('menolak kasir dari outlet lain dan staf non-kasir', function () {
    $this->postJson('/api/v1/pos/auth/pin', ['staff_id' => $this->dagoCashier->id, 'pin' => '3867'], bearer($this->deviceToken))
        ->assertForbidden();
    $this->postJson('/api/v1/pos/auth/pin', ['staff_id' => $this->kitchen->id, 'pin' => '5566'], bearer($this->deviceToken))
        ->assertForbidden();
});

it('mengunci PIN setelah 5 kali salah (NFR-SEC-03)', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/pos/auth/pin', ['staff_id' => $this->cashierMember->id, 'pin' => '0000'], bearer($this->deviceToken))
            ->assertUnprocessable();
    }

    $this->postJson('/api/v1/pos/auth/pin', ['staff_id' => $this->cashierMember->id, 'pin' => '7351'], bearer($this->deviceToken))
        ->assertStatus(423);

    // Manajer dapat membuka kunci dari back-office.
    $this->postJson("/api/v1/staff/{$this->cashierMember->id}/unlock-pin", [], asMember($this->manager, $this->company))->assertOk();
    $this->postJson('/api/v1/pos/auth/pin', ['staff_id' => $this->cashierMember->id, 'pin' => '7351'], bearer($this->deviceToken))
        ->assertOk();
});

it('menolak staf dari company lain', function () {
    [$other] = Factory::company('Warung Bu Ratna');
    $otherOutlet = Factory::outlet($other);
    [, $foreign] = Factory::staff($other, ['cashier'], [$otherOutlet->id], '2749');

    $this->postJson('/api/v1/pos/auth/pin', ['staff_id' => $foreign->id, 'pin' => '2749'], bearer($this->deviceToken))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'STAFF_NOT_FOUND');
});

describe('otorisasi supervisor (FR-AUTH-07)', function () {
    it('menampilkan daftar supervisor beserta aksi yang boleh disetujui', function () {
        [, $admin] = Factory::staff($this->company, ['company_admin'], [], '615283');

        $rows = collect($this->getJson('/api/v1/pos/supervisors', bearer($this->deviceToken))->assertOk()->json('data'));

        expect($rows->pluck('id')->all())->toContain($this->managerMember->id, $admin->id)
            ->not->toContain($this->cashierMember->id, $this->kitchen->id)
            ->and($rows->firstWhere('id', $this->managerMember->id)['actions'])->toContain('void', 'discount', 'refund');
    });

    it('memberi otorisasi void dengan PIN manajer dan mencatat audit log', function () {
        $posToken = $this->postJson('/api/v1/pos/auth/pin', ['staff_id' => $this->cashierMember->id, 'pin' => '7351'], bearer($this->deviceToken))->json('data.token');
        $orderId = (string) Str::uuid7();

        $response = $this->postJson('/api/v1/pos/authorize', [
            'action' => 'void', 'supervisor_id' => $this->managerMember->id, 'pin' => '482915', 'reason' => 'Salah input menu',
            'reference_type' => 'order_item', 'reference_id' => $orderId, 'amount' => '22000',
        ], bearer($posToken));

        $response->assertOk()
            ->assertJsonPath('data.action', 'void')
            ->assertJsonPath('data.authorized_by.id', $this->manager->id);

        $log = Factory::tenant($this->company, fn () => AuditLog::query()->findOrFail($response->json('data.authorization_id')));
        expect($log->action)->toBe('pos.authorization_granted')
            ->and($log->user_id)->toBe($this->cashier->id)
            ->and($log->authorized_by)->toBe($this->manager->id)
            ->and($log->device_id)->toBe($this->device->id)
            ->and($log->reason)->toBe('Salah input menu')
            ->and($log->metadata['reference_id'])->toBe($orderId);
    });

    it('memberi pesan yang sama untuk semua penyebab penolakan', function (string $who, string $pin, string $cause) {
        $id = match ($who) {
            'cashier' => $this->cashierMember->id,
            'manager' => $this->managerMember->id,
            'unknown' => (string) Str::uuid7(),
        };

        $response = $this->postJson('/api/v1/pos/authorize', ['action' => 'void', 'supervisor_id' => $id, 'pin' => $pin, 'reason' => 'Coba'], bearer($this->deviceToken));

        $response->assertForbidden();
        expect($response->json('errors.0.message'))->toBe('Otorisasi ditolak. Periksa nama supervisor dan PIN.');
        $failed = Factory::tenant($this->company, fn () => AuditLog::query()->where('action', 'pos.authorization_failed')->latest('created_at')->first());
        expect($failed->metadata['cause'])->toBe($cause);
    })->with([
        'PIN kasir benar tapi tidak berwenang' => ['cashier', '7351', 'not_permitted'],
        'PIN manajer salah' => ['manager', '111222', 'wrong_pin'],
        'supervisor tidak dikenal' => ['unknown', '482915', 'supervisor_not_found'],
    ]);

    it('mengunci PIN supervisor setelah 5 kali salah', function () {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/pos/authorize', ['action' => 'void', 'supervisor_id' => $this->managerMember->id, 'pin' => '10000'.$i, 'reason' => 'x'], bearer($this->deviceToken))
                ->assertForbidden();
        }

        $this->postJson('/api/v1/pos/authorize', ['action' => 'void', 'supervisor_id' => $this->managerMember->id, 'pin' => '482915', 'reason' => 'x'], bearer($this->deviceToken))
            ->assertStatus(423);
    });

    it('menahan perangkat setelah terlalu banyak kegagalan otorisasi', function () {
        $supervisors = collect(range(1, 3))->map(fn ($i) => Factory::staff($this->company, ['outlet_manager'], [$this->kemang->id], '58372'.$i)[1]);

        foreach (range(0, 9) as $i) {
            $member = $supervisors[$i % 3];
            $this->postJson('/api/v1/pos/authorize', ['action' => 'void', 'supervisor_id' => $member->id, 'pin' => '99990'.$i, 'reason' => 'x'], bearer($this->deviceToken))
                ->assertForbidden();
        }

        $this->postJson('/api/v1/pos/authorize', ['action' => 'void', 'supervisor_id' => $this->managerMember->id, 'pin' => '482915', 'reason' => 'x'], bearer($this->deviceToken))
            ->assertStatus(429);
    });

    it('menolak supervisor yang tidak memegang outlet perangkat', function () {
        [, $dagoManager] = Factory::staff($this->company, ['outlet_manager'], [$this->dago->id], '615283');

        $this->postJson('/api/v1/pos/authorize', ['action' => 'void', 'supervisor_id' => $dagoManager->id, 'pin' => '615283', 'reason' => 'Coba'], bearer($this->deviceToken))
            ->assertForbidden();
    });

    it('menolak supervisor dari company lain', function () {
        [$other] = Factory::company('Warung Bu Ratna');
        [, $foreign] = Factory::staff($other, ['company_admin'], [], '615283');

        $this->postJson('/api/v1/pos/authorize', ['action' => 'void', 'supervisor_id' => $foreign->id, 'pin' => '615283', 'reason' => 'Coba'], bearer($this->deviceToken))
            ->assertForbidden();
    });

    it('menegakkan batas diskon per role (BR-14)', function () {
        $payload = ['action' => 'discount', 'supervisor_id' => $this->managerMember->id, 'pin' => '482915'];

        $this->postJson('/api/v1/pos/authorize', $payload + ['discount_percent' => '30'], bearer($this->deviceToken))->assertOk();
        $this->postJson('/api/v1/pos/authorize', $payload + ['discount_percent' => '60'], bearer($this->deviceToken))
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'DISCOUNT_LIMIT_EXCEEDED');
    });

    it('mewajibkan alasan, supervisor, dan aksi yang dikenal', function () {
        $this->postJson('/api/v1/pos/authorize', ['action' => 'void', 'supervisor_id' => $this->managerMember->id, 'pin' => '482915'], bearer($this->deviceToken))
            ->assertUnprocessable();
        $this->postJson('/api/v1/pos/authorize', ['action' => 'void', 'pin' => '482915', 'reason' => 'x'], bearer($this->deviceToken))
            ->assertUnprocessable();
        $this->postJson('/api/v1/pos/authorize', ['action' => 'hapus_semua', 'supervisor_id' => $this->managerMember->id, 'pin' => '482915', 'reason' => 'x'], bearer($this->deviceToken))
            ->assertUnprocessable();
    });

    it('tidak dapat dipanggil dengan token back-office', function () {
        $this->postJson('/api/v1/pos/authorize', ['action' => 'void', 'supervisor_id' => $this->managerMember->id, 'pin' => '482915', 'reason' => 'x'], asMember($this->owner, $this->company))
            ->assertForbidden();
    });
});
