<?php

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Refund;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Factory;
use Tests\Support\Pos;

beforeEach(function () {
    $this->pos = Pos::setup();
    [$this->shiftId, $this->ymd] = $this->pos->openShift('cashier', '500000');
    $this->orderId = $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)))['order_id'];
});

function voidPayload(Pos $pos, string $orderId, string $who, array $extra = []): array
{
    return ['order_id' => $orderId, 'voided_by' => $pos->userId($who), 'reason' => 'Salah input menu', 'created_at' => now()->toIso8601String()] + $extra;
}

function refundPayload(Pos $pos, string $orderId, string $shiftId, string $amount, array $extra = []): array
{
    return array_replace([
        'order_id' => $orderId, 'shift_id' => $shiftId, 'amount' => $amount, 'method' => 'cash', 'stock_action' => 'return',
        'reason' => 'Pesanan tidak sesuai', 'refunded_by' => $pos->userId('manager'), 'created_at' => now()->toIso8601String(),
    ], $extra);
}

describe('void setelah bayar (FR-POS-16, BR-12)', function () {
    it('manajer dapat void dan kas seharusnya tidak lagi menghitung transaksi itu', function () {
        $token = $this->pos->login('manager');

        $response = $this->postJson("/api/v1/pos/orders/{$this->orderId}/void", ['id' => (string) Str::uuid7(), 'reason' => 'Salah input menu'], bearer($token));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'voided')
            ->assertJsonPath('data.void_reason', 'Salah input menu')
            ->assertJsonPath('data.items.0.status', 'voided');

        $report = $this->getJson("/api/v1/pos/shifts/{$this->shiftId}/report", bearer($token))->assertOk();
        $report->assertJsonPath('data.report.cash.expected', '500000.00')
            ->assertJsonPath('data.report.void_count', 1)
            ->assertJsonPath('data.report.void_after_payment_total', '78500.00');

        $log = $this->pos->tenant(fn () => AuditLog::query()->where('action', 'order.voided')->sole());
        expect($log->user_id)->toBe($this->pos->userId('manager'))->and($log->reason)->toBe('Salah input menu');

        // Void kedua ditolak
        $this->postJson("/api/v1/pos/orders/{$this->orderId}/void", ['id' => (string) Str::uuid7(), 'reason' => 'Lagi'], bearer($token))
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'ORDER_NOT_VOIDABLE');
    });

    it('kasir memerlukan otorisasi supervisor untuk void', function () {
        expect($this->pos->push('order.void', voidPayload($this->pos, $this->orderId, 'cashier'))['error']['code'])->toBe('AUTHORIZATION_REQUIRED');

        $auth = $this->pos->authorize('void', 'manager', ['reference_id' => $this->orderId]);
        $result = $this->pos->push('order.void', voidPayload($this->pos, $this->orderId, 'cashier', ['authorization' => ['mode' => 'online', 'authorization_id' => $auth]]));
        expect($result['status'])->toBe('accepted');

        $order = $this->pos->tenant(fn () => Order::query()->find($this->orderId));
        expect($order->voided_by)->toBe($this->pos->userId('cashier'))->and($order->void_authorized_by)->toBe($this->pos->userId('manager'));
    });

    it('ID manajer yang dikirim perangkat tanpa login PIN tidak memberi hak void', function () {
        $result = $this->pos->push('order.void', voidPayload($this->pos, $this->orderId, 'manager'));
        expect($result['error']['code'])->toBe('AUTHORIZATION_REQUIRED');

        // Otorisasi untuk transaksi lain atau nominal lain ditolak.
        $wrong = $this->pos->authorize('void', 'manager', ['reference_id' => (string) Str::uuid7()]);
        expect($this->pos->push('order.void', voidPayload($this->pos, $this->orderId, 'cashier', ['authorization' => ['mode' => 'online', 'authorization_id' => $wrong]]))['error']['code'])
            ->toBe('AUTHORIZATION_INVALID');
        $wrongAmount = $this->pos->authorize('void', 'manager', ['reference_id' => $this->orderId, 'amount' => '1000']);
        expect($this->pos->push('order.void', voidPayload($this->pos, $this->orderId, 'cashier', ['authorization' => ['mode' => 'online', 'authorization_id' => $wrongAmount]]))['error']['code'])
            ->toBe('AUTHORIZATION_INVALID');

        $right = $this->pos->authorize('void', 'manager', ['reference_id' => $this->orderId, 'amount' => '78500']);
        expect($this->pos->push('order.void', voidPayload($this->pos, $this->orderId, 'cashier', ['authorization' => ['mode' => 'online', 'authorization_id' => $right]]))['status'])
            ->toBe('accepted');
    });

    it('void untuk transaksi yang belum diterima server dapat dikirim ulang', function () {
        $result = $this->pos->push('order.void', voidPayload($this->pos, (string) Str::uuid7(), 'manager'));

        expect($result['error']['code'])->toBe('ORDER_NOT_FOUND')->and($result['error']['retryable'])->toBeTrue();
    });

    it('setelah shift ditutup koreksi harus lewat refund (BR-13)', function () {
        $this->pos->push('shift.close', ['shift_id' => $this->shiftId, 'closed_by' => $this->pos->userId('cashier'), 'closed_at' => now()->toIso8601String(), 'counted_cash' => '578500']);

        expect($this->pos->push('order.void', voidPayload($this->pos, $this->orderId, 'manager'))['error']['code'])->toBe('VOID_SHIFT_CLOSED');
    });
});

describe('refund (FR-POS-17)', function () {
    it('refund sebagian per baris lalu sisanya tanpa selisih pembulatan', function () {
        $token = $this->pos->token('manager');
        $croissant = $this->pos->tenant(fn () => Order::query()->with('items')->find($this->orderId)->items->firstWhere('line_no', 1));
        $coffee = $this->pos->tenant(fn () => Order::query()->with('items')->find($this->orderId)->items->firstWhere('line_no', 2));

        // Porsi 1 croissant = 25.000 / 68.000 × 78.500 = 28.860,29
        $wrong = $this->postJson("/api/v1/pos/orders/{$this->orderId}/refunds", [
            'id' => (string) Str::uuid7(), 'shift_id' => $this->shiftId, 'amount' => '25000', 'method' => 'cash',
            'stock_action' => 'waste', 'reason' => 'Croissant gosong', 'lines' => [['order_item_id' => $croissant->id, 'qty' => 1]],
        ], bearer($token));
        $wrong->assertUnprocessable()->assertJsonPath('errors.0.code', 'REFUND_AMOUNT_MISMATCH')->assertJsonPath('errors.0.details.expected', '28860.29');

        $first = $this->postJson("/api/v1/pos/orders/{$this->orderId}/refunds", [
            'id' => (string) Str::uuid7(), 'shift_id' => $this->shiftId, 'amount' => '28860.29', 'method' => 'cash',
            'stock_action' => 'waste', 'reason' => 'Croissant gosong', 'lines' => [['order_item_id' => $croissant->id, 'qty' => 1]],
        ], bearer($token));
        $first->assertCreated()->assertJsonPath('data.amount', '28860.29')->assertJsonPath('data.stock_action', 'waste');

        $order = $this->pos->tenant(fn () => Order::query()->find($this->orderId));
        expect($order->status)->toBe('partially_refunded')->and($order->refunded_total)->toBe('28860.29');

        // Qty melebihi yang dibeli
        $over = $this->pos->pushAs('manager', 'order.refund', refundPayload($this->pos, $this->orderId, $this->shiftId, '57720.58', ['lines' => [['order_item_id' => $croissant->id, 'qty' => 2]]]));
        expect($over['error']['code'])->toBe('REFUND_QTY_EXCEEDED');

        // Sisa: 78.500 − 28.860,29 = 49.639,71 (bukan 28.860,29 + 20.779,41 = 49.639,70)
        $rest = $this->pos->pushAs('manager', 'order.refund', refundPayload($this->pos, $this->orderId, $this->shiftId, '49639.71', [
            'lines' => [['order_item_id' => $croissant->id, 'qty' => 1], ['order_item_id' => $coffee->id, 'qty' => 1]],
        ]));
        expect($rest['status'])->toBe('accepted');
        expect($this->pos->tenant(fn () => Order::query()->find($this->orderId)->status))->toBe('refunded');

        // Kas seharusnya = 500.000 + 78.500 − 78.500
        $this->getJson("/api/v1/pos/shifts/{$this->shiftId}/report", bearer($token))
            ->assertJsonPath('data.report.cash.expected', '500000.00')
            ->assertJsonPath('data.report.refunds.cash.amount', '78500.00')
            ->assertJsonPath('data.report.net_sales', '0.00');

        expect($this->pos->pushAs('manager', 'order.refund', refundPayload($this->pos, $this->orderId, $this->shiftId, '1'))['error']['code'])->toBe('ORDER_NOT_REFUNDABLE');
    });

    it('kasir memerlukan otorisasi dan metode refund dibatasi', function () {
        $payload = refundPayload($this->pos, $this->orderId, $this->shiftId, '78500', ['refunded_by' => $this->pos->userId('cashier')]);
        expect($this->pos->push('order.refund', $payload)['error']['code'])->toBe('AUTHORIZATION_REQUIRED');

        $payload['authorization'] = ['mode' => 'online', 'authorization_id' => $this->pos->authorize('refund', 'manager', ['reference_id' => $this->orderId])];
        $payload['method'] = 'credit';
        expect($this->pos->push('order.refund', $payload)['error']['code'])->toBe('REFUND_METHOD_INVALID');

        $payload['method'] = 'cash';
        $payload['authorization'] = ['mode' => 'online', 'authorization_id' => $this->pos->authorize('refund', 'manager', ['reference_id' => $this->orderId, 'amount' => '78500'])];
        $result = $this->pos->push('order.refund', $payload);
        expect($result['status'])->toBe('accepted');
        $refund = $this->pos->tenant(fn () => Refund::query()->find($result['refund_id']));
        expect($refund->authorized_by)->toBe($this->pos->userId('manager'))->and($refund->refunded_by)->toBe($this->pos->userId('cashier'));
    });

    it('refund tunai untuk pembayaran non-tunai hanya dengan persetujuan manajer', function () {
        $payload = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 2));
        $payload['payments'][0] = ['id' => (string) Str::uuid7(), 'method' => 'debit', 'amount' => '78500', 'created_at' => now()->toIso8601String()];
        $debitOrder = $this->pos->push('order', $payload)['order_id'];

        // Supervisor tanpa izin tutup hari tidak cukup untuk mengubah metode refund.
        $role = app(TenantContext::class)->runAsSystem(function () {
            $role = Role::query()->create(['company_id' => $this->pos->company->id, 'name' => 'supervisor', 'guard_name' => 'web', 'label' => 'Supervisor', 'max_discount_percent' => '0']);
            app(PermissionRegistrar::class)->setPermissionsTeamId($this->pos->company->id);
            $role->syncPermissions(['pos.transact', 'pos.void']);

            return $role;
        });
        [$supervisor, $member] = Factory::staff($this->pos->company, [$role->name], [$this->pos->outlet->id], '918273');
        $this->pos->addStaff('supervisor', $supervisor, $member, '918273');
        $cash = refundPayload($this->pos, $debitOrder, $this->shiftId, '78500', ['refunded_by' => $supervisor->id]);
        expect($this->pos->pushAs('supervisor', 'order.refund', $cash)['error']['code'])->toBe('REFUND_METHOD_INVALID');

        $cash['refunded_by'] = $this->pos->userId('manager');
        $result = $this->pos->pushAs('manager', 'order.refund', $cash);
        expect($result['status'])->toBe('accepted');
        expect($this->pos->tenant(fn () => Refund::query()->find($result['refund_id'])->flags))->toBe(['refund_method_changed']);
    });

    it('refund untuk hari yang sudah ditutup hanya dengan persetujuan manajer (BR-13)', function () {
        // Shift & transaksi kemarin → tutup shift → tutup hari kemarin → shift baru hari ini.
        $this->pos->push('shift.close', ['shift_id' => $this->shiftId, 'closed_by' => $this->pos->userId('cashier'), 'closed_at' => now()->toIso8601String(), 'counted_cash' => '578500']);
        $yesterday = now()->subDay();
        $oldShift = (string) Str::uuid7();
        expect($this->pos->push('shift.open', ['cashier_id' => $this->pos->userId('cashier'), 'opening_cash' => '0', 'opened_at' => $yesterday->toIso8601String()], $oldShift)['status'])->toBe('accepted');
        $oldYmd = $yesterday->copy()->timezone('Asia/Jakarta')->format('ymd');
        $old = $this->pos->order($oldShift, $this->pos->receipt($oldYmd, 1), [
            'created_at' => $yesterday->copy()->addMinutes(5)->toIso8601String(),
            'completed_at' => $yesterday->copy()->addMinutes(6)->toIso8601String(),
        ]);
        $orderId = $this->pos->push('order', $old)['order_id'];
        $this->pos->push('shift.close', ['shift_id' => $oldShift, 'closed_by' => $this->pos->userId('cashier'), 'closed_at' => $yesterday->copy()->addHour()->toIso8601String(), 'counted_cash' => '78500']);

        $owner = Factory::ownerOf($this->pos->company);
        $date = $yesterday->copy()->timezone('Asia/Jakarta')->format('Y-m-d');
        $this->postJson("/api/v1/outlets/{$this->pos->outlet->id}/end-of-day", ['business_date' => $date], asMember($owner, $this->pos->company))->assertCreated();

        // Hari yang sudah ditutup tidak bisa dibuka shift lagi.
        $sameDay = $this->pos->push('shift.open', ['cashier_id' => $this->pos->userId('cashier'), 'opening_cash' => '0', 'opened_at' => $yesterday->toIso8601String()]);
        expect($sameDay['error']['code'])->toBe('BUSINESS_DAY_CLOSED');

        [$newShift] = $this->pos->openShift('cashier', '0');

        // Supervisor khusus: boleh void/refund tetapi tidak boleh tutup hari.
        $role = app(TenantContext::class)->runAsSystem(function () {
            $role = Role::query()->create(['company_id' => $this->pos->company->id, 'name' => 'supervisor', 'guard_name' => 'web', 'label' => 'Supervisor', 'max_discount_percent' => '10']);
            app(PermissionRegistrar::class)->setPermissionsTeamId($this->pos->company->id);
            $role->syncPermissions(['pos.transact', 'pos.shift', 'pos.void']);

            return $role;
        });
        [$supervisor, $supervisorMember] = Factory::staff($this->pos->company, [$role->name], [$this->pos->outlet->id], '918273');
        $this->pos->addStaff('supervisor', $supervisor, $supervisorMember, '918273');

        $payload = refundPayload($this->pos, $orderId, $newShift, '78500', ['refunded_by' => $supervisor->id]);
        expect($this->pos->pushAs('supervisor', 'order.refund', $payload)['error']['code'])->toBe('REFUND_REQUIRES_MANAGER');

        // Kasir dengan otorisasi supervisor (tanpa izin tutup hari) juga ditolak.
        $payload['refunded_by'] = $this->pos->userId('cashier');
        $payload['authorization'] = ['mode' => 'offline', 'supervisor_id' => $supervisor->id];
        expect($this->pos->push('order.refund', $payload)['error']['code'])->toBe('REFUND_REQUIRES_MANAGER');
        unset($payload['authorization']);

        $payload['refunded_by'] = $this->pos->userId('manager');
        $result = $this->pos->pushAs('manager', 'order.refund', $payload);
        expect($result['status'])->toBe('accepted');

        $refund = $this->pos->tenant(fn () => Refund::query()->find($result['refund_id']));
        expect($refund->business_date->format('Y-m-d'))->toBe(now()->timezone('Asia/Jakarta')->format('Y-m-d'))
            ->and($refund->order_business_date->format('Y-m-d'))->toBe($date)
            ->and($refund->flags)->toBe(['closed_day_refund']);
    });
});
