<?php

use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sync\Domain\Models\SyncBatch;
use Illuminate\Support\Str;
use Tests\Support\Factory;
use Tests\Support\Pos;

beforeEach(function () {
    $this->pos = Pos::setup();
});

/** Antrian outbox satu shift penuh: buka shift → 2 transaksi → kas keluar → tutup shift. */
function outbox(Pos $pos, string $shiftId, string $ymd): array
{
    $opened = now()->subHours(2);

    return [
        ['type' => 'shift.open', 'id' => $shiftId, 'payload' => ['cashier_id' => $pos->userId('cashier'), 'opening_cash' => '200000', 'opened_at' => $opened->toIso8601String()]],
        ['type' => 'order', 'id' => (string) Str::uuid7(), 'payload' => $pos->order($shiftId, $pos->receipt($ymd, 1))],
        ['type' => 'order', 'id' => (string) Str::uuid7(), 'payload' => $pos->order($shiftId, $pos->receipt($ymd, 2))],
        ['type' => 'cash_movement', 'id' => (string) Str::uuid7(), 'payload' => ['shift_id' => $shiftId, 'type' => 'out', 'amount' => '15000', 'reason' => 'Galon', 'created_by' => $pos->userId('cashier'), 'created_at' => now()->subMinutes(5)->toIso8601String()]],
        ['type' => 'shift.close', 'id' => (string) Str::uuid7(), 'payload' => ['shift_id' => $shiftId, 'closed_by' => $pos->userId('cashier'), 'closed_at' => now()->subMinute()->toIso8601String(), 'counted_cash' => '342000']],
    ];
}

it('menerima antrian offline secara berurutan dan idempoten (NFR-OFF-01, G8)', function () {
    $this->freezeSecond();
    $shiftId = (string) Str::uuid7();
    $ymd = now()->subHours(2)->timezone('Asia/Jakarta')->format('ymd');
    $entities = outbox($this->pos, $shiftId, $ymd);
    $batch = (string) Str::uuid7();

    $response = $this->postJson('/api/v1/sync/push', ['batch_id' => $batch, 'entities' => $entities], bearer($this->pos->deviceToken) + [
        'X-Device-Time' => now()->addSeconds(90)->toIso8601String(),
    ]);

    $response->assertOk()
        ->assertJsonPath('data.accepted', 5)
        ->assertJsonPath('data.rejected', 0)
        ->assertJsonPath('data.clock_offset_seconds', 90)
        // 200.000 + 78.500 × 2 − 15.000 = 342.000
        ->assertJsonPath('data.results.4.expected_cash', '342000.00')
        ->assertJsonPath('data.results.4.cash_variance', '0.00');
    expect(collect($response->json('data.results'))->pluck('status')->unique()->all())->toBe(['accepted']);

    // Koneksi putus sebelum respons diterima → perangkat mengirim ulang batch yang sama.
    $retry = $this->postJson('/api/v1/sync/push', ['batch_id' => $batch, 'entities' => $entities], bearer($this->pos->deviceToken));
    $retry->assertOk()->assertJsonPath('data.duplicate', 5)->assertJsonPath('data.accepted', 0);
    expect($retry->json('data.results.1.order_id'))->toBe($entities[1]['id']);

    expect($this->pos->tenant(fn () => Order::query()->count()))->toBe(2);
    $saved = $this->pos->tenant(fn () => SyncBatch::query()->find($batch));
    expect($saved->accepted_count)->toBe(5)->and($saved->duplicate_count)->toBe(5)->and($saved->clock_offset_seconds)->toBe(90);
});

it('ID sama dengan isi berbeda ditolak sebagai konflik', function () {
    [$shiftId, $ymd] = $this->pos->openShift();
    $id = (string) Str::uuid7();
    $this->pos->push('order', $this->pos->order($shiftId, $this->pos->receipt($ymd, 1)), $id);

    $changed = $this->pos->order($shiftId, $this->pos->receipt($ymd, 1));
    $changed['note'] = 'diubah';
    $result = $this->pos->push('order', $changed, $id);

    expect($result['status'])->toBe('rejected')->and($result['error']['code'])->toBe('CONFLICT');
});

it('entitas yang bergantung pada entitas belum diterima boleh dikirim ulang', function () {
    $shiftId = (string) Str::uuid7();
    $ymd = now()->subHours(2)->timezone('Asia/Jakarta')->format('ymd');
    [$open, $order] = outbox($this->pos, $shiftId, $ymd);

    $first = $this->pos->push('order', $order['payload'], $order['id']);
    expect($first['error']['code'])->toBe('SHIFT_NOT_FOUND')->and($first['error']['retryable'])->toBeTrue();

    expect($this->pos->push('shift.open', $open['payload'], $shiftId)['status'])->toBe('accepted');
    expect($this->pos->push('order', $order['payload'], $order['id'])['status'])->toBe('accepted');
});

it('membatasi ukuran batch dan jenis entitas', function () {
    $entity = ['type' => 'order', 'id' => (string) Str::uuid7(), 'payload' => ['x' => 1]];
    $this->postJson('/api/v1/sync/push', ['batch_id' => (string) Str::uuid7(), 'entities' => array_fill(0, 101, $entity)], bearer($this->pos->deviceToken))
        ->assertUnprocessable();
    $this->postJson('/api/v1/sync/push', ['batch_id' => (string) Str::uuid7(), 'entities' => [['type' => 'menu.update', 'id' => (string) Str::uuid7(), 'payload' => []]]], bearer($this->pos->deviceToken))
        ->assertUnprocessable();

    $result = $this->pos->push('order', ['shift_id' => 'bukan-uuid']);
    expect($result['error']['code'])->toBe('VALIDATION_FAILED')->and($result['error']['details']['errors'])->toHaveKey('receipt_no');
});

it('batch milik perangkat lain tidak dapat dipakai ulang', function () {
    $batch = (string) Str::uuid7();
    [$shiftId] = $this->pos->openShift();
    $this->postJson('/api/v1/sync/push', ['batch_id' => $batch, 'entities' => [
        ['type' => 'cash_movement', 'id' => (string) Str::uuid7(), 'payload' => ['shift_id' => $shiftId, 'type' => 'in', 'amount' => '1000', 'reason' => 'x', 'created_by' => $this->pos->userId('cashier'), 'created_at' => now()->toIso8601String()]],
    ]], bearer($this->pos->deviceToken))->assertOk();

    [, $otherToken] = Factory::pairedDevice($this->pos->company, $this->pos->outlet);
    $this->postJson('/api/v1/sync/push', ['batch_id' => $batch, 'entities' => [['type' => 'order', 'id' => (string) Str::uuid7(), 'payload' => ['note' => 'x']]]], bearer($otherToken))
        ->assertStatus(409)->assertJsonPath('errors.0.code', 'BATCH_CONFLICT');
});

it('perangkat lain di outlet yang sama tidak dapat memakai shift perangkat ini', function () {
    [$shiftId, $ymd] = $this->pos->openShift();
    [, $otherToken] = Factory::pairedDevice($this->pos->company, $this->pos->outlet);

    $result = $this->pos->push('order', $this->pos->order($shiftId, $this->pos->receipt($ymd, 1)), token: $otherToken);

    expect($result['error']['code'])->toBe('SHIFT_NOT_ON_DEVICE');
});

it('isolasi tenant: perangkat company lain tidak melihat shift/transaksi company ini', function () {
    [$shiftId, $ymd] = $this->pos->openShift();
    $orderId = $this->pos->push('order', $this->pos->order($shiftId, $this->pos->receipt($ymd, 1)))['order_id'];
    $other = Pos::setup('Warung Bu Ratna', 'WBR');

    // Shift company lain tidak terlihat → diperlakukan seperti belum diterima.
    $payload = $other->order($shiftId, $other->receipt($ymd, 1));
    expect($other->push('order', $payload)['error']['code'])->toBe('SHIFT_NOT_FOUND');
    expect($other->push('order.void', ['order_id' => $orderId, 'voided_by' => $other->userId('manager'), 'reason' => 'Salah input', 'created_at' => now()->toIso8601String()])['error']['code'])->toBe('ORDER_NOT_FOUND');

    $otherToken = $other->login('manager');
    $this->getJson("/api/v1/pos/orders/{$orderId}", bearer($otherToken))->assertNotFound();
    $this->getJson("/api/v1/pos/shifts/{$shiftId}/report", bearer($otherToken))->assertNotFound();
    expect($this->getJson('/api/v1/pos/orders?receipt_no='.urlencode($this->pos->receipt($ymd, 1)), bearer($otherToken))->json('data'))->toBe([]);

    // Memakai ID transaksi company lain → konflik tanpa membocorkan isi, dan tidak diulang terus-menerus.
    [$otherShift, $otherYmd] = $other->openShift();
    $clash = $other->push('order', $other->order($otherShift, $other->receipt($otherYmd, 1)), $orderId);
    expect($clash['error'])->toBe(['code' => 'CONFLICT', 'message' => 'ID atau nomor struk sudah dipakai data lain.', 'retryable' => false]);
    expect($this->pos->tenant(fn () => Order::query()->find($orderId)->company_id))->toBe($this->pos->company->id);
});

describe('tarik data master (FR-DEV-03)', function () {
    it('mengirim snapshot penuh saat versi tertinggal lalu kosong saat sudah terbaru', function () {
        $full = $this->getJson('/api/v1/sync/pull', bearer($this->pos->deviceToken))->assertOk();

        $full->assertJsonPath('data.mode', 'full')
            ->assertJsonPath('data.snapshot.outlet.code', 'KMG')
            ->assertJsonPath('data.snapshot.device.code', $this->pos->device->code)
            ->assertJsonPath('data.open_shift', null)
            ->assertJsonPath('data.business_day_closed', false);
        $version = $full->json('data.version');
        expect($full->json('data.snapshot.catalog.items'))->toHaveCount(2);
        expect(collect($full->json('data.snapshot.payment_methods'))->pluck('method')->all())->toBe(['cash', 'qris', 'debit', 'credit', 'ewallet', 'transfer']);

        $staff = collect($full->json('data.snapshot.staff'));
        expect($staff->pluck('user_id')->all())->toContain($this->pos->userId('cashier'), $this->pos->userId('manager'), $this->pos->userId('admin'))
            ->not->toContain($this->pos->userId('kitchen'), $this->pos->userId('other_cashier'));
        expect($staff->firstWhere('user_id', $this->pos->userId('manager')))->toMatchArray(['max_discount_percent' => '50.00'])
            ->not->toHaveKey('pin_hash');
        expect(json_encode($full->json()))->not->toContain('pin_hash');

        $this->getJson("/api/v1/sync/pull?since={$version}", bearer($this->pos->deviceToken))
            ->assertOk()->assertJsonPath('data.mode', 'none')->assertJsonMissingPath('data.snapshot');

        // Perubahan menu menaikkan versi.
        $this->pos->tenant(fn () => Item::query()->findOrFail($this->pos->croissant->id)->update(['base_price' => '26000']));
        $next = $this->getJson("/api/v1/sync/pull?since={$version}", bearer($this->pos->deviceToken))->assertOk();
        expect($next->json('data.mode'))->toBe('full')->and($next->json('data.version'))->toBeGreaterThan($version);
    });

    it('perubahan ketersediaan dan metode bayar juga menaikkan versi', function () {
        $version = $this->getJson('/api/v1/sync/pull', bearer($this->pos->deviceToken))->json('data.version');
        $token = $this->pos->login('cashier');
        $this->postJson("/api/v1/pos/items/{$this->pos->croissant->id}/sold-out", ['sold_out' => true], bearer($token))->assertOk();
        $afterSoldOut = $this->getJson("/api/v1/sync/pull?since={$version}", bearer($this->pos->deviceToken))->json('data.version');
        expect($afterSoldOut)->toBeGreaterThan($version);

        $owner = Factory::ownerOf($this->pos->company);
        $this->putJson("/api/v1/outlets/{$this->pos->outlet->id}/payment-methods", ['methods' => [
            ['method' => 'ewallet', 'label' => 'E-Wallet', 'is_active' => true, 'sort_order' => 5, 'mdr_percent' => '1.5', 'mdr_fixed' => '0'],
        ]], asMember($owner, $this->pos->company))->assertOk();
        expect($this->getJson("/api/v1/sync/pull?since={$afterSoldOut}", bearer($this->pos->deviceToken))->json('data.mode'))->toBe('full');
    });

    it('menyertakan shift terbuka perangkat', function () {
        [$shiftId] = $this->pos->openShift();

        $this->getJson('/api/v1/sync/pull', bearer($this->pos->deviceToken))->assertJsonPath('data.open_shift.id', $shiftId);
    });

    it('endpoint sinkronisasi tidak dapat dipakai token back-office', function () {
        $owner = Factory::ownerOf($this->pos->company);

        $this->getJson('/api/v1/sync/pull', asMember($owner, $this->pos->company))->assertForbidden();
        $this->postJson('/api/v1/sync/push', [], asMember($owner, $this->pos->company))->assertForbidden();
    });
});

it('perangkat yang belum menarik data master tidak dapat membuktikan harga yang berbeda', function () {
    [$device, $token] = Factory::pairedDevice($this->pos->company, $this->pos->outlet);
    $newPos = clone $this->pos;
    $newPos->device = $device;
    $newPos->deviceToken = $token;
    [$shiftId, $ymd] = $newPos->openShift();

    // Harga sesuai katalog tetap diterima.
    expect($newPos->push('order', $newPos->order($shiftId, $newPos->receipt($ymd, 1)))['status'])->toBe('accepted');

    // Pengaturan berbeda (nama pajak) → wajib sinkron dulu (boleh dikirim ulang).
    $payload = $newPos->order($shiftId, $newPos->receipt($ymd, 2));
    $payload['pricing']['tax_name'] = 'PPN';
    $result = $newPos->push('order', $payload);
    expect($result['error']['code'])->toBe('SYNC_REQUIRED')->and($result['error']['retryable'])->toBeTrue();
});

it('perubahan sebelum tarik data terakhir tidak dapat dipakai untuk memundurkan waktu transaksi', function () {
    // Harga croissant diubah, perangkat menarik data terbaru, lalu membuka shift.
    $this->pos->tenant(fn () => Item::query()->findOrFail($this->pos->croissant->id)->update(['base_price' => '30000']));
    $this->travel(2)->seconds();
    $this->getJson('/api/v1/sync/pull', bearer($this->pos->deviceToken))->assertOk();
    [$shiftId, $ymd] = $this->pos->openShift();

    // Transaksi "dimundurkan" dengan harga lama 25.000 → dianggap ubah harga manual.
    $payload = $this->pos->order($shiftId, $this->pos->receipt($ymd, 1));
    expect($this->pos->push('order', $payload)['error']['code'])->toBe('AUTHORIZATION_REQUIRED');
});

it('penjualan offline tetap diterima walau perangkat menarik data sebelum antrian terkirim', function () {
    [$shiftId, $ymd] = $this->pos->openShift();
    $this->travel(2)->seconds();
    // Harga naik saat perangkat offline; perangkat tetap menjual dengan harga lama.
    $this->pos->tenant(fn () => Item::query()->findOrFail($this->pos->croissant->id)->update(['base_price' => '30000']));
    $sale = $this->pos->order($shiftId, $this->pos->receipt($ymd, 1));

    // Saat online, perangkat (keliru) menarik data lebih dulu, baru mengirim antrian.
    $this->travel(2)->seconds();
    $this->getJson('/api/v1/sync/pull', bearer($this->pos->deviceToken))->assertOk();
    $result = $this->pos->push('order', $sale);

    expect($result['status'])->toBe('accepted')->and($result['flags'])->toContain('price_mismatch');
});

it('suntingan menu selain harga tidak membenarkan harga yang lebih rendah', function () {
    [$shiftId, $ymd] = $this->pos->openShift();
    $this->travel(2)->seconds();
    $this->pos->tenant(fn () => Item::query()->findOrFail($this->pos->croissant->id)->update(['description' => 'Resep baru']));

    $payload = $this->pos->order($shiftId, $this->pos->receipt($ymd, 1), [
        'totals' => ['subtotal' => '18002', 'item_discount' => '0', 'order_discount' => '0', 'service_charge' => '900.10', 'tax' => '1890.21', 'rounding' => '7.69', 'total' => '20800'],
        'payments' => [['id' => (string) Str::uuid7(), 'method' => 'cash', 'amount' => '20800', 'created_at' => now()->toIso8601String()]],
    ]);
    $payload['lines'][0]['unit_price'] = '1';

    expect($this->pos->push('order', $payload)['error']['code'])->toBe('AUTHORIZATION_REQUIRED');
});
