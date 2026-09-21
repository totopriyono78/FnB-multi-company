<?php

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Factory;

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
});

it('tidak dapat diubah atau dihapus lewat model (FR-AUD-01)', function () {
    Factory::brand($this->company);
    $log = Factory::tenant($this->company, fn () => AuditLog::query()->firstOrFail());

    expect(fn () => Factory::tenant($this->company, fn () => $log->forceFill(['action' => 'x'])->save()))->toThrow(LogicException::class)
        ->and(fn () => Factory::tenant($this->company, fn () => $log->delete()))->toThrow(LogicException::class);
});

it('tidak dapat diubah atau dihapus langsung di database, termasuk oleh role pemilik', function (string $sql) {
    Factory::brand($this->company);

    DB::transaction(fn () => DB::statement($sql));
})->with([
    'update' => ["UPDATE audit_logs SET action = 'dihapus'"],
    'delete' => ['DELETE FROM audit_logs'],
])->throws(QueryException::class, 'append-only');

it('dapat dicari berdasarkan user, entitas, aksi, dan rentang waktu (FR-AUD-02)', function () {
    $brand = Factory::brand($this->company, ['code' => 'KTJ']);
    $headers = asMember($this->owner, $this->company);
    $this->patchJson("/api/v1/brands/{$brand->id}", ['name' => 'Kopi Tepi Jalan Baru'], $headers)->assertOk();

    $byAction = $this->getJson('/api/v1/audit-logs?action=brand.updated', $headers)->assertOk();
    expect($byAction->json('data'))->toHaveCount(1)
        ->and($byAction->json('data.0.user.id'))->toBe($this->owner->id)
        ->and($byAction->json('data.0.new_values.name'))->toBe('Kopi Tepi Jalan Baru')
        ->and($byAction->json('data.0.old_values'))->toHaveKey('name');

    $this->getJson("/api/v1/audit-logs?entity=Brand&entity_id={$brand->id}", $headers)->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/audit-logs?user_id={$this->owner->id}&action=brand.", $headers)->assertOk()->assertJsonCount(1, 'data');

    $today = now('Asia/Jakarta')->toDateString();
    $this->getJson("/api/v1/audit-logs?from={$today}&to={$today}&action=brand.", $headers)->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/audit-logs?from=2020-01-01&to=2020-01-31', $headers)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/audit-logs?from=2026-02-01&to=2026-01-01', $headers)->assertUnprocessable();
});

it('menyamarkan nilai sensitif', function () {
    $outlet = Factory::outlet($this->company);
    [, $member] = Factory::staff($this->company, ['cashier'], [$outlet->id], '7351');
    Factory::tenant($this->company, fn () => app(AuditLogger::class)
        ->log('uji.redaksi', $member, ['pin_hash' => 'lama'], ['pin_hash' => '$2y$10$contoh', 'password' => 'Rahasia123', 'employee_code' => 'KMG-9']));

    $raw = Factory::system(fn () => DB::table('audit_logs')->pluck('old_values')->merge(DB::table('audit_logs')->pluck('new_values'))->filter()->implode(' '));

    expect($raw)->not->toContain('$2y$')
        ->and($raw)->not->toContain('Rahasia123')
        ->and($raw)->toContain('[disembunyikan]')
        ->and($raw)->toContain('KMG-9');
});

it('membatasi manajer outlet pada aktivitas outletnya', function () {
    $kemang = Factory::outlet($this->company, null, ['code' => 'KMG']);
    $dago = Factory::outlet($this->company, null, ['code' => 'DGO']);
    [$manager] = Factory::staff($this->company, ['outlet_manager'], [$kemang->id]);
    [$kemangCashier] = Factory::staff($this->company, ['cashier'], [$kemang->id]);
    [$dagoCashier] = Factory::staff($this->company, ['cashier'], [$dago->id]);

    Factory::tenant($this->company, function () use ($kemangCashier, $dagoCashier) {
        app(AuditLogger::class)->log('uji.kemang', userId: $kemangCashier->id);
        app(AuditLogger::class)->log('uji.dago', userId: $dagoCashier->id);
    });

    $actions = collect($this->getJson('/api/v1/audit-logs?action=uji.', asMember($manager, $this->company))->assertOk()->json('data'))->pluck('action');
    expect($actions->all())->toBe(['uji.kemang']);

    [$cashier] = Factory::staff($this->company, ['cashier'], [$kemang->id]);
    $this->getJson('/api/v1/audit-logs', asMember($cashier, $this->company))->assertForbidden();
});

it('menyimpan audit log di partisi bulanan', function () {
    Factory::brand($this->company);

    $partitions = Factory::system(fn () => DB::select('SELECT DISTINCT tableoid::regclass::text AS p FROM audit_logs'));

    expect(collect($partitions)->pluck('p')->all())->toBe(['audit_logs_'.now('UTC')->format('Y_m')]);
});
