<?php

use App\Filament\Pages\EndOfDay;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OutletResource\Pages\EditOutlet;
use App\Filament\Resources\OutletResource\RelationManagers\PaymentMethodsRelationManager;
use App\Filament\Resources\ShiftResource\Pages\ListShifts;
use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Payment\Domain\Models\OutletPaymentMethod;
use App\Modules\Sales\Domain\Models\BusinessDay;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Tenancy\Application\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Factory;
use Tests\Support\Pos;

beforeEach(function () {
    $this->pos = Pos::setup();
    $this->owner = Factory::ownerOf($this->pos->company);
    [$this->shiftId, $this->ymd] = $this->pos->openShift();
    // Harga croissant di perangkat 26.000 (katalog 25.000): 52.000 + 18.000 = 70.000; SC 3.500; PB1 7.350; 80.850 → 80.900
    $flagged = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 2), [
        'totals' => ['subtotal' => '70000', 'item_discount' => '0', 'order_discount' => '0', 'service_charge' => '3500', 'tax' => '7350', 'rounding' => '50', 'total' => '80900'],
        'payments' => [['id' => (string) Str::uuid7(), 'method' => 'cash', 'amount' => '80900', 'created_at' => now()->toIso8601String()]],
    ]);
    $flagged['lines'][0]['unit_price'] = '26000';
    $this->orderId = $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)))['order_id'];
    $this->flaggedId = $this->pos->push('order', $flagged)['order_id'];
    $this->base = "/admin/{$this->pos->company->code}";
    // Request API di atas menjadikan sanctum guard bawaan; panel memakai guard web.
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
});

it('membuka halaman penjualan untuk pemilik', function (string $path) {
    $this->actingAs($this->owner)->get($this->base.$path)->assertOk();
})->with([
    'transaksi' => ['/transaksi'],
    'shift' => ['/shift'],
    'tutup hari' => ['/tutup-hari'],
]);

it('menampilkan rincian transaksi beserta tanda tinjauan', function () {
    $this->actingAs($this->owner)->get("{$this->base}/transaksi/{$this->flaggedId}")
        ->assertOk()
        ->assertSee($this->pos->receipt($this->ymd, 2))
        ->assertSee('Harga beda dengan katalog')
        ->assertSee('Harga katalog Rp25.000')
        ->assertSee('Rp80.900')
        ->assertSee('Tunai');

    $this->actingAs($this->owner)->get("{$this->base}/shift/{$this->shiftId}")
        ->assertOk()
        ->assertSee('Kas seharusnya')
        ->assertSee('Rp659.400'); // 500.000 + 78.500 + 80.900
});

it('menyaring transaksi yang perlu ditinjau', function () {
    $this->actingAs($this->owner);
    Filament::setTenant($this->pos->company);
    app(TenantContext::class)->setTenant($this->pos->company->id);

    $orders = Order::query()->get();
    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords($orders)
        ->filterTable('flagged', true)
        ->assertCanSeeTableRecords($orders->where('id', $this->flaggedId))
        ->assertCanNotSeeTableRecords($orders->where('id', $this->orderId));

    Livewire::test(ListShifts::class)->assertCanSeeTableRecords(Shift::query()->get());
});

it('kasir, dapur, dan manajer outlet lain tidak dapat membuka data penjualan', function (string $who, string $path, int $status) {
    $user = match ($who) {
        'dago' => Factory::staff($this->pos->company, ['outlet_manager'], [$this->pos->otherOutlet->id])[0],
        default => $this->pos->staff[$who]['user'],
    };
    $path = str_replace(['{order}', '{shift}'], [$this->orderId, $this->shiftId], $path);

    $this->actingAs($user)->get($this->base.$path)->assertStatus($status);
})->with([
    'kasir: transaksi' => ['cashier', '/transaksi', 403],
    'dapur: tutup hari' => ['kitchen', '/tutup-hari', 403],
    'dapur: shift' => ['kitchen', '/shift', 403],
    // Data di luar cakupan tidak terlihat sama sekali.
    'manajer outlet lain: detail transaksi' => ['dago', '/transaksi/{order}', 404],
    'manajer outlet lain: detail shift' => ['dago', '/shift/{shift}', 404],
]);

it('company lain tidak dapat membuka transaksi', function () {
    [$other, $otherOwner] = Factory::company('Warung Bu Ratna');
    $this->actingAs($otherOwner)->get("/admin/{$other->code}/transaksi/{$this->orderId}")->assertNotFound();
});

it('tutup hari dari back-office setelah shift ditutup', function () {
    $this->actingAs($this->owner);
    Filament::setTenant($this->pos->company);
    app(TenantContext::class)->setTenant($this->pos->company->id);
    $date = Shift::query()->findOrFail($this->shiftId)->business_date->format('Y-m-d');

    Livewire::test(EndOfDay::class, ['outletId' => $this->pos->outlet->id, 'date' => $date])
        ->assertSee('Masih ada 1 shift terbuka')
        ->assertActionDisabled('close');

    app('auth')->forgetGuards();
    $this->pos->push('shift.close', ['shift_id' => $this->shiftId, 'closed_by' => $this->pos->userId('cashier'), 'closed_at' => now()->toIso8601String(), 'counted_cash' => '659400']);
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $this->actingAs($this->owner);
    app(TenantContext::class)->setTenant($this->pos->company->id);

    Livewire::test(EndOfDay::class, ['outletId' => $this->pos->outlet->id, 'date' => $date])
        ->assertSee('Rp159.400')
        ->assertActionEnabled('close')
        ->callAction('close', data: ['note' => 'Aman'])
        ->assertNotified('Hari bisnis ditutup.');

    expect(BusinessDay::query()->where('outlet_id', $this->pos->outlet->id)->sole()->summary['note'])->toBe('Aman');

    Livewire::test(EndOfDay::class, ['outletId' => $this->pos->outlet->id, 'date' => $date])
        ->assertSee('Hari bisnis sudah ditutup')
        ->assertActionDisabled('close');
});

it('mengatur metode pembayaran outlet dan mencegah semua metode nonaktif', function () {
    $this->actingAs($this->owner);
    Filament::setTenant($this->pos->company);
    app(TenantContext::class)->setTenant($this->pos->company->id);
    $qris = OutletPaymentMethod::query()->where('outlet_id', $this->pos->outlet->id)->where('method', 'qris')->sole();

    $rm = Livewire::test(PaymentMethodsRelationManager::class, ['ownerRecord' => $this->pos->outlet, 'pageClass' => EditOutlet::class]);
    $rm->assertCanSeeTableRecords(OutletPaymentMethod::query()->where('outlet_id', $this->pos->outlet->id)->get())
        ->callTableAction('edit', $qris, data: ['label' => 'QRIS Dinamis', 'sort_order' => 1, 'mdr_percent' => '0.7', 'mdr_fixed' => '0', 'is_active' => true])
        ->assertHasNoTableActionErrors();
    expect($qris->refresh()->label)->toBe('QRIS Dinamis')->and($qris->mdr_percent)->toBe('0.70');
    expect(AuditLog::query()->where('action', 'outlet.payment_method_updated')->count())->toBe(1);

    $rm->callTableAction('edit', $qris, data: ['label' => 'QRIS', 'sort_order' => 1, 'mdr_percent' => '101', 'mdr_fixed' => '0', 'is_active' => true])
        ->assertHasTableActionErrors(['mdr_percent']);

    // Nonaktifkan semua kecuali tunai, lalu coba nonaktifkan tunai.
    OutletPaymentMethod::query()->where('outlet_id', $this->pos->outlet->id)->where('method', '<>', 'cash')->update(['is_active' => false]);
    $cash = OutletPaymentMethod::query()->where('outlet_id', $this->pos->outlet->id)->where('method', 'cash')->sole();
    Livewire::test(PaymentMethodsRelationManager::class, ['ownerRecord' => $this->pos->outlet, 'pageClass' => EditOutlet::class])
        ->callTableAction('edit', $cash, data: ['label' => 'Tunai', 'sort_order' => 1, 'mdr_percent' => '0', 'mdr_fixed' => '0', 'is_active' => false])
        ->assertNotified('Minimal satu metode pembayaran harus aktif.');
    expect($cash->refresh()->is_active)->toBeTrue();
});

it('manajer outlet hanya dapat melihat metode pembayaran', function () {
    $this->actingAs($this->pos->staff['manager']['user']);
    Filament::setTenant($this->pos->company);
    app(TenantContext::class)->setTenant($this->pos->company->id);
    $cash = OutletPaymentMethod::query()->where('outlet_id', $this->pos->outlet->id)->where('method', 'cash')->sole();

    expect(PaymentMethodsRelationManager::canViewForRecord($this->pos->outlet, EditOutlet::class))->toBeTrue();
    Livewire::test(PaymentMethodsRelationManager::class, ['ownerRecord' => $this->pos->outlet, 'pageClass' => EditOutlet::class])
        ->assertTableActionHidden('edit', $cash);
});
