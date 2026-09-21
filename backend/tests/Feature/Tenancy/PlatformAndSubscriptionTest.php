<?php

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Tenancy\Domain\Models\Company;
use Tests\Support\Factory;

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->admin = Factory::system(function () {
        $u = Factory::user(['name' => 'Tim Operasional']);
        $u->forceFill(['is_platform_admin' => true])->save();

        return $u;
    });
});

it('hanya super admin yang dapat mengelola tenant (FR-TEN-02)', function () {
    $this->getJson('/api/v1/platform/companies', asMember($this->owner))->assertForbidden();

    $list = $this->getJson('/api/v1/platform/companies', asMember($this->admin))->assertOk();
    expect(collect($list->json('data'))->pluck('id'))->toContain($this->company->id);
});

it('membuat tenant untuk pemilik yang sudah punya akun', function () {
    $owner = Factory::user(['email' => 'dimas@sotokudus.test']);

    $this->postJson('/api/v1/platform/companies', ['name' => 'Soto Kudus Pak Dimas', 'owner_email' => 'dimas@sotokudus.test', 'plan_code' => 'basic'], asMember($this->admin))
        ->assertCreated()
        ->assertJsonPath('data.subscription.plan.code', 'basic');

    $this->postJson('/api/v1/platform/companies', ['name' => 'X', 'owner_email' => 'belum@ada.test'], asMember($this->admin))
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'OWNER_NOT_FOUND');

    expect($owner->accessibleCompanies())->toHaveCount(1);
});

it('menangguhkan dan mengaktifkan kembali tenant', function () {
    $this->postJson("/api/v1/platform/companies/{$this->company->id}/suspend", ['reason' => 'Tunggakan 3 bulan'], asMember($this->admin))
        ->assertOk()->assertJsonPath('data.status', 'suspended');

    $blocked = $this->getJson('/api/v1/brands', asMember($this->owner, $this->company));
    $blocked->assertForbidden();
    expect(firstErrorCode($blocked))->toBe('COMPANY_UNAVAILABLE');

    $this->postJson("/api/v1/platform/companies/{$this->company->id}/activate", [], asMember($this->admin))
        ->assertOk()->assertJsonPath('data.status', 'active');
    $this->getJson('/api/v1/brands', asMember($this->owner, $this->company))->assertOk();

    $logs = Factory::tenant($this->company, fn () => AuditLog::query()->where('action', 'like', 'platform.%')->pluck('action')->all());
    expect($logs)->toContain('platform.company_suspended', 'platform.company_activated');
});

it('menghapus tenant secara soft delete', function () {
    $this->deleteJson("/api/v1/platform/companies/{$this->company->id}", ['reason' => 'Permintaan pemilik'], asMember($this->admin))->assertOk();

    expect(Factory::system(fn () => Company::withTrashed()->find($this->company->id)?->trashed()))->toBeTrue();
    $this->getJson('/api/v1/brands', asMember($this->owner, $this->company))->assertForbidden();
    $this->postJson('/api/v1/platform/companies/'.Str::uuid7().'/activate', [], asMember($this->admin))->assertNotFound();
});

describe('mode baca-saja setelah masa tenggang (FR-TEN-08)', function () {
    beforeEach(function () {
        $this->outlet = Factory::outlet($this->company);
        [$this->device, $this->deviceToken] = Factory::pairedDevice($this->company, $this->outlet);
        Factory::system(fn () => $this->company->forceFill(['subscription_ends_at' => now()->subDays(8)])->save());
    });

    it('memblokir perubahan data back-office tetapi tetap mengizinkan melihat', function () {
        $headers = asMember($this->owner, $this->company);

        $this->getJson('/api/v1/outlets', $headers)->assertOk();
        $this->getJson('/api/v1/company', $headers)->assertOk()->assertJsonPath('data.read_only', true);

        $blocked = $this->postJson('/api/v1/brands', ['code' => 'BARU', 'name' => 'Baru'], $headers);
        $blocked->assertStatus(402);
        expect(firstErrorCode($blocked))->toBe('SUBSCRIPTION_READ_ONLY');
    });

    it('tidak memblokir operasional POS', function () {
        [, $member] = Factory::staff($this->company, ['cashier'], [$this->outlet->id], '7351');

        $this->postJson('/api/v1/devices/heartbeat', ['pending_sync_count' => 2], bearer($this->deviceToken))->assertOk();
        $this->postJson('/api/v1/pos/auth/pin', ['staff_id' => $member->id, 'pin' => '7351'], bearer($this->deviceToken))->assertOk();
    });

    it('masih dapat menulis selama masa tenggang', function () {
        Factory::system(fn () => $this->company->forceFill(['subscription_ends_at' => now()->subDays(6)])->save());

        $this->postJson('/api/v1/brands', ['code' => 'BARU', 'name' => 'Baru'], asMember($this->owner, $this->company))->assertCreated();
    });
});
