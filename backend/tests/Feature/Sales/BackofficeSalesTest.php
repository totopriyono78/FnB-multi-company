<?php

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Catalog\Domain\Models\OutletItemAvailability;
use App\Modules\Payment\Application\PaymentMethods;
use App\Modules\Sales\Domain\Models\BusinessDay;
use App\Modules\Sales\Domain\Models\Shift;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Factory;
use Tests\Support\Pos;

beforeEach(function () {
    $this->pos = Pos::setup();
    $this->owner = Factory::ownerOf($this->pos->company);
    $this->headers = asMember($this->owner, $this->pos->company);
    [$this->shiftId, $this->ymd] = $this->pos->openShift('cashier', '100000');
    $this->orderId = $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)))['order_id'];
    $this->date = $this->pos->tenant(fn () => Shift::query()->find($this->shiftId)->business_date->format('Y-m-d'));
});

it('menampilkan daftar & detail transaksi dan shift untuk back-office (FR-POS-04)', function () {
    $list = $this->getJson('/api/v1/orders?date_from='.$this->date.'&date_to='.$this->date, $this->headers)->assertOk();
    assertStandardEnvelope($list);
    $list->assertJsonPath('data.0.id', $this->orderId)
        ->assertJsonPath('data.0.total', '78500.00')
        ->assertJsonPath('meta.pagination.total', 1);
    expect($list->json('data.0'))->not->toHaveKey('items');

    $this->getJson("/api/v1/orders/{$this->orderId}", $this->headers)
        ->assertOk()
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.payments.0.method', 'cash');

    $this->getJson('/api/v1/orders?search='.urlencode($this->pos->receipt($this->ymd, 1)).'&date_from='.$this->date, $this->headers)
        ->assertOk()->assertJsonPath('meta.pagination.total', 1);
    $this->getJson('/api/v1/orders?flagged=1&date_from='.$this->date, $this->headers)
        ->assertOk()->assertJsonPath('meta.pagination.total', 0);

    $this->getJson('/api/v1/shifts?status=open', $this->headers)->assertOk()->assertJsonPath('data.0.id', $this->shiftId);
    $this->getJson("/api/v1/shifts/{$this->shiftId}", $this->headers)
        ->assertOk()
        ->assertJsonPath('data.report.cash.expected', '178500.00')
        ->assertJsonPath('data.cash_movements', []);
});

it('membatasi transaksi sesuai izin & cakupan outlet', function () {
    // Manajer outlet lain tidak melihat transaksi outlet ini.
    [$dagoManager] = Factory::staff($this->pos->company, ['outlet_manager'], [$this->pos->otherOutlet->id]);
    $headers = asMember($dagoManager, $this->pos->company);
    $this->getJson('/api/v1/orders?date_from='.$this->date, $headers)->assertOk()->assertJsonPath('meta.pagination.total', 0);
    $this->getJson("/api/v1/orders/{$this->orderId}", $headers)->assertNotFound();
    $this->getJson("/api/v1/shifts/{$this->shiftId}", $headers)->assertNotFound();

    // Manajer outlet ini melihat.
    $this->getJson("/api/v1/orders/{$this->orderId}", asMember($this->pos->staff['manager']['user'], $this->pos->company))->assertOk();

    // Kasir (tanpa izin laporan) dan dapur ditolak.
    $this->getJson('/api/v1/orders', asMember($this->pos->staff['cashier']['user'], $this->pos->company))->assertForbidden();
    $this->getJson('/api/v1/shifts', asMember($this->pos->staff['kitchen']['user'], $this->pos->company))->assertForbidden();
});

it('isolasi tenant: company lain tidak dapat membaca transaksi', function () {
    [$other, $otherOwner] = Factory::company('Warung Bu Ratna');
    $headers = asMember($otherOwner, $other);

    $this->getJson("/api/v1/orders/{$this->orderId}", $headers)->assertNotFound();
    $this->getJson("/api/v1/shifts/{$this->shiftId}", $headers)->assertNotFound();
    $this->getJson('/api/v1/orders?date_from='.$this->date, $headers)->assertOk()->assertJsonPath('meta.pagination.total', 0);
    $this->getJson("/api/v1/outlets/{$this->pos->outlet->id}/end-of-day", $headers)->assertNotFound();
    $this->postJson("/api/v1/outlets/{$this->pos->outlet->id}/end-of-day", ['business_date' => $this->date], $headers)->assertNotFound();
    $this->getJson("/api/v1/outlets/{$this->pos->outlet->id}/payment-methods", $headers)->assertNotFound();

    // Header company dimanipulasi → ditolak
    $this->getJson("/api/v1/orders/{$this->orderId}", asMember($otherOwner, $this->pos->company))->assertForbidden();
});

describe('tutup hari (FR-POS-05)', function () {
    it('menolak tutup hari selama masih ada shift terbuka', function () {
        $preview = $this->getJson("/api/v1/outlets/{$this->pos->outlet->id}/end-of-day?business_date={$this->date}", $this->headers)->assertOk();
        $preview->assertJsonPath('data.closed', false)
            ->assertJsonPath('data.open_shifts.0.id', $this->shiftId)
            ->assertJsonPath('data.summary.sales_total', '78500.00');

        $this->postJson("/api/v1/outlets/{$this->pos->outlet->id}/end-of-day", ['business_date' => $this->date], $this->headers)
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'SHIFTS_OPEN')
            ->assertJsonPath('errors.0.details.shift_ids', [$this->shiftId]);
    });

    it('menutup hari, menyimpan ringkasan, dan mengembalikan status habis', function () {
        $this->pos->tenant(fn () => OutletItemAvailability::query()->create(['outlet_id' => $this->pos->outlet->id, 'item_id' => $this->pos->croissant->id, 'is_listed' => true, 'is_sold_out' => true]));
        $version = $this->getJson('/api/v1/sync/pull', bearer($this->pos->deviceToken))->json('data.version');
        $this->pos->push('shift.close', ['shift_id' => $this->shiftId, 'closed_by' => $this->pos->userId('cashier'), 'closed_at' => now()->toIso8601String(), 'counted_cash' => '178000', 'variance_note' => 'Kurang 500']);

        $response = $this->postJson("/api/v1/outlets/{$this->pos->outlet->id}/end-of-day", ['business_date' => $this->date, 'note' => 'Hujan deras'], $this->headers);

        $response->assertCreated()
            ->assertJsonPath('data.summary.order_count', 1)
            ->assertJsonPath('data.summary.net_sales', '78500.00')
            ->assertJsonPath('data.summary.payments.cash.amount', '78500.00')
            ->assertJsonPath('data.summary.cash_variance_total', '-500.00')
            ->assertJsonPath('data.summary.sold_out_reset', 1)
            ->assertJsonPath('data.summary.note', 'Hujan deras');

        expect($this->pos->tenant(fn () => OutletItemAvailability::query()->where('item_id', $this->pos->croissant->id)->value('is_sold_out')))->toBeFalse();
        expect($this->getJson("/api/v1/sync/pull?since={$version}", bearer($this->pos->deviceToken))->json('data.mode'))->toBe('full');
        expect($this->pos->tenant(fn () => AuditLog::query()->where('action', 'business_day.closed')->sole()->user_id))->toBe($this->owner->id);

        $this->postJson("/api/v1/outlets/{$this->pos->outlet->id}/end-of-day", ['business_date' => $this->date], $this->headers)
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'BUSINESS_DAY_CLOSED');
        $this->getJson("/api/v1/outlets/{$this->pos->outlet->id}/end-of-day?business_date={$this->date}", $this->headers)
            ->assertOk()->assertJsonPath('data.closed', true)->assertJsonPath('data.closed_by', $this->owner->id);

        // Data tutup hari tidak dapat dihapus.
        expect(fn () => $this->pos->tenant(fn () => BusinessDay::query()->delete()))->toThrow(QueryException::class);
    });

    it('hanya pemegang izin tutup hari yang boleh menutup, dan tidak untuk hari mendatang', function () {
        $this->pos->push('shift.close', ['shift_id' => $this->shiftId, 'closed_by' => $this->pos->userId('cashier'), 'closed_at' => now()->toIso8601String(), 'counted_cash' => '178500']);

        [$brandManager] = Factory::staff($this->pos->company, ['brand_manager'], [], null, [$this->pos->outlet->brand_id]);
        $this->postJson("/api/v1/outlets/{$this->pos->outlet->id}/end-of-day", ['business_date' => $this->date], asMember($brandManager, $this->pos->company))
            ->assertForbidden();

        $future = now()->addDays(3)->format('Y-m-d');
        $this->postJson("/api/v1/outlets/{$this->pos->outlet->id}/end-of-day", ['business_date' => $future], $this->headers)
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'BUSINESS_DAY_IN_FUTURE');

        $this->postJson("/api/v1/outlets/{$this->pos->outlet->id}/end-of-day", ['business_date' => $this->date], asMember($this->pos->staff['manager']['user'], $this->pos->company))
            ->assertCreated();
    });
});

describe('metode pembayaran outlet (FR-PAY-02, FR-PAY-10)', function () {
    it('outlet baru mendapat metode bawaan dan MDR dapat diatur', function () {
        $methods = $this->getJson("/api/v1/outlets/{$this->pos->outlet->id}/payment-methods", $this->headers)->assertOk();
        expect(collect($methods->json('data'))->where('is_active', true)->pluck('method')->all())->toBe(['cash', 'qris', 'debit', 'credit']);

        $this->putJson("/api/v1/outlets/{$this->pos->outlet->id}/payment-methods", ['methods' => [
            ['method' => 'qris', 'label' => 'QRIS', 'is_active' => true, 'sort_order' => 2, 'mdr_percent' => '0.7', 'mdr_fixed' => '0'],
            ['method' => 'credit', 'label' => 'Kartu Kredit', 'is_active' => false, 'sort_order' => 4, 'mdr_percent' => '2', 'mdr_fixed' => '0'],
        ]], $this->headers)->assertOk()->assertJsonPath('data.1.mdr_percent', '0.70');

        expect($this->pos->tenant(fn () => AuditLog::query()->where('action', 'outlet.payment_method_updated')->count()))->toBe(2);

        // MDR dihitung pada pembayaran: debit 0% (bawaan); QRIS 0,7% × 78.500 = 549,50
        $this->pos->tenant(fn () => DB::table('outlet_payment_methods')->where('outlet_id', $this->pos->outlet->id)->where('method', 'debit')->update(['mdr_percent' => '1', 'mdr_fixed' => '500']));
        $payload = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 2));
        $payload['payments'][0] = ['id' => (string) Str::uuid7(), 'method' => 'debit', 'amount' => '78500', 'created_at' => now()->toIso8601String()];
        $orderId = $this->pos->push('order', $payload)['order_id'];
        // Debit 1% × 78.500 + 500 = 1.285
        $this->getJson("/api/v1/orders/{$orderId}", $this->headers)->assertJsonPath('data.payments.0.mdr_amount', '1285.00');
    });

    it('minimal satu metode aktif dan hanya pengelola outlet yang boleh mengubah', function () {
        $all = collect(PaymentMethods::DEFAULTS)->map(fn ($d, $m) => [
            'method' => $m, 'label' => $d['label'], 'is_active' => false, 'sort_order' => 1, 'mdr_percent' => '0', 'mdr_fixed' => '0',
        ])->values()->all();
        $this->putJson("/api/v1/outlets/{$this->pos->outlet->id}/payment-methods", ['methods' => $all], $this->headers)
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'NO_ACTIVE_METHOD');

        $this->putJson("/api/v1/outlets/{$this->pos->outlet->id}/payment-methods", ['methods' => [
            ['method' => 'member_balance', 'label' => 'Saldo', 'is_active' => true, 'sort_order' => 1, 'mdr_percent' => '0', 'mdr_fixed' => '0'],
        ]], $this->headers)->assertUnprocessable();

        $this->putJson("/api/v1/outlets/{$this->pos->outlet->id}/payment-methods", ['methods' => [$all[0]]], asMember($this->pos->staff['manager']['user'], $this->pos->company))
            ->assertForbidden();
    });
});
