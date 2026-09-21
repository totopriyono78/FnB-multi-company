<?php

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Sales\Domain\Models\Shift;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Pos;

beforeEach(function () {
    $this->pos = Pos::setup();
});

it('membuka shift dari POS dengan modal awal dan mencatat audit (FR-POS-01)', function () {
    $token = $this->pos->login('cashier');
    $id = (string) Str::uuid7();

    $response = $this->postJson('/api/v1/pos/shifts', ['id' => $id, 'opening_cash' => '500000'], bearer($token));

    $response->assertCreated()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.opening_cash', '500000.00')
        ->assertJsonPath('data.cashier_id', $this->pos->userId('cashier'))
        ->assertJsonPath('meta.sync_status', 'accepted');
    assertStandardEnvelope($response);

    // Kirim ulang permintaan yang sama (beberapa detik kemudian, waktu diisi server) → tidak membuat shift baru.
    $this->travel(3)->seconds();
    $this->postJson('/api/v1/pos/shifts', ['id' => $id, 'opening_cash' => '500000'], bearer($token))
        ->assertOk()->assertJsonPath('meta.sync_status', 'duplicate');

    $this->getJson('/api/v1/pos/shifts/current', bearer($token))->assertOk()->assertJsonPath('data.id', $id);
    expect($this->pos->tenant(fn () => AuditLog::query()->where('action', 'shift.opened')->count()))->toBe(1);
});

it('menolak shift kedua di perangkat yang sama', function () {
    $this->pos->openShift();
    $token = $this->pos->login('cashier');

    $this->postJson('/api/v1/pos/shifts', ['id' => (string) Str::uuid7(), 'opening_cash' => '0'], bearer($token))
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'SHIFT_ALREADY_OPEN');
});

it('hanya staf dengan izin shift di outlet perangkat yang boleh membuka shift', function () {
    $kitchen = $this->pos->push('shift.open', ['cashier_id' => $this->pos->userId('kitchen'), 'opening_cash' => '0', 'opened_at' => now()->toIso8601String()]);
    $other = $this->pos->push('shift.open', ['cashier_id' => $this->pos->userId('other_cashier'), 'opening_cash' => '0', 'opened_at' => now()->toIso8601String()]);

    expect($kitchen['status'])->toBe('rejected')->and($kitchen['error']['code'])->toBe('STAFF_NOT_ALLOWED')
        ->and($other['error']['code'])->toBe('STAFF_NOT_ALLOWED');
});

it('menolak waktu perangkat yang jauh di depan jam server', function () {
    $result = $this->pos->push('shift.open', ['cashier_id' => $this->pos->userId('cashier'), 'opening_cash' => '0', 'opened_at' => now()->addHour()->toIso8601String()]);

    expect($result['error']['code'])->toBe('CLOCK_AHEAD');
});

it('menghitung kas seharusnya saat tutup shift dengan hitung buta (FR-POS-02, FR-POS-03)', function () {
    [$shiftId, $ymd] = $this->pos->openShift('cashier', '500000');
    $token = $this->pos->login('cashier');

    // Kas masuk 50.000, kas keluar 20.000
    $this->postJson("/api/v1/pos/shifts/{$shiftId}/cash-movements", ['id' => (string) Str::uuid7(), 'type' => 'in', 'amount' => '50000', 'reason' => 'Tambah uang kecil'], bearer($token))->assertCreated();
    $this->postJson("/api/v1/pos/shifts/{$shiftId}/cash-movements", ['id' => (string) Str::uuid7(), 'type' => 'out', 'amount' => '20000', 'reason' => 'Beli es batu'], bearer($token))->assertCreated();
    // Penjualan tunai 78.500 (bayar 100.000, kembali 21.500)
    expect($this->pos->push('order', $this->pos->order($shiftId, $this->pos->receipt($ymd, 1)))['status'])->toBe('accepted');

    // Kas seharusnya = 500.000 + 78.500 + 50.000 − 20.000 = 608.500
    $report = $this->getJson("/api/v1/pos/shifts/{$shiftId}/report", bearer($token))->assertOk();
    $report->assertJsonPath('data.report.cash.expected', '608500.00')
        ->assertJsonPath('data.report.order_count', 1)
        ->assertJsonPath('data.report.payments.cash.amount', '78500.00');

    // Selisih tanpa keterangan ditolak
    $this->postJson("/api/v1/pos/shifts/{$shiftId}/close", ['id' => (string) Str::uuid7(), 'counted_cash' => '608000'], bearer($token))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'VARIANCE_NOTE_REQUIRED')
        ->assertJsonPath('errors.0.details.variance', '-500.00');

    $closed = $this->postJson("/api/v1/pos/shifts/{$shiftId}/close", [
        'id' => (string) Str::uuid7(), 'counted_cash' => '608000', 'variance_note' => 'Uang receh kurang',
        'denominations' => ['100000' => 6, '5000' => 1, '1000' => 3],
    ], bearer($token))->assertCreated();

    $closed->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.expected_cash', '608500.00')
        ->assertJsonPath('data.counted_cash', '608000.00')
        ->assertJsonPath('data.cash_variance', '-500.00')
        ->assertJsonPath('data.summary.cash.in', '50000.00');

    // Transaksi pada shift yang sudah ditutup ditolak.
    $late = $this->pos->push('order', $this->pos->order($shiftId, $this->pos->receipt($ymd, 2)));
    expect($late['error']['code'])->toBe('SHIFT_CLOSED');
});

it('buka laci tanpa transaksi memerlukan izin atau otorisasi supervisor (FR-POS-15)', function () {
    [$shiftId] = $this->pos->openShift();
    $payload = ['shift_id' => $shiftId, 'type' => 'drawer_open', 'reason' => 'Tukar uang', 'created_by' => $this->pos->userId('cashier'), 'created_at' => now()->toIso8601String()];

    expect($this->pos->push('cash_movement', $payload)['error']['code'])->toBe('AUTHORIZATION_REQUIRED');

    $auth = $this->pos->authorize('open_drawer');
    $result = $this->pos->push('cash_movement', $payload + ['authorization' => ['mode' => 'online', 'authorization_id' => $auth]]);
    expect($result['error'] ?? null)->toBeNull();
    expect($result['status'])->toBe('accepted');

    $movement = $this->pos->tenant(fn () => DB::table('cash_movements')->where('id', $result['cash_movement_id'])->first());
    expect($movement->authorized_by)->toBe($this->pos->userId('manager'))->and((string) $movement->amount)->toBe('0.00');

    // Satu otorisasi hanya untuk satu kali buka laci.
    $again = $this->pos->push('cash_movement', $payload + ['authorization' => ['mode' => 'online', 'authorization_id' => $auth]]);
    expect($again['error']['code'])->toBe('AUTHORIZATION_USED');

    // Manajer yang login sendiri tidak perlu otorisasi; ID manajer dari perangkat tetap perlu.
    $asManager = ['created_by' => $this->pos->userId('manager')] + $payload;
    expect($this->pos->push('cash_movement', $asManager)['error']['code'])->toBe('AUTHORIZATION_REQUIRED');
    expect($this->pos->pushAs('manager', 'cash_movement', $asManager)['status'])->toBe('accepted');
});

it('shift yang sudah ditutup tidak dapat diubah di database', function () {
    [$shiftId] = $this->pos->openShift('cashier', '0');
    $close = $this->pos->push('shift.close', ['shift_id' => $shiftId, 'closed_by' => $this->pos->userId('cashier'), 'closed_at' => now()->toIso8601String(), 'counted_cash' => '0']);
    expect($close['status'])->toBe('accepted')->and($close['cash_variance'])->toBe('0.00');

    expect(fn () => $this->pos->tenant(fn () => Shift::query()->whereKey($shiftId)->update(['counted_cash' => '1000'])))
        ->toThrow(QueryException::class, 'tidak dapat diubah');
});
