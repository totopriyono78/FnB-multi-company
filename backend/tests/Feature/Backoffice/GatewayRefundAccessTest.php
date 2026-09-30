<?php

use App\Filament\Resources\GatewayRefundRequestResource;
use App\Filament\Resources\GatewayRefundRequestResource\Pages\ListGatewayRefundRequests;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\GatewayRefundRequest;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Refund;
use App\Modules\Tenancy\Application\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Factory;
use Tests\Support\Pos;

/**
 * Siapa yang boleh menyatakan "dana sudah dikembalikan" — dan apa yang terjadi saat ia mengatakannya.
 *
 * Aksinya diuji lewat form Livewire yang sesungguhnya, bukan lewat panggilan langsung ke service.
 * Itu pelajaran dari layar kredensial payment gateway (27 Sep 2026): 24 uji lulus sementara form
 * aslinya meledak, karena tidak satu pun uji melewati jalur yang dipakai manusia.
 */
beforeEach(function () {
    $this->pos = Pos::setup();
    [$this->shiftId, $this->ymd] = $this->pos->openShift('cashier', '500000');
    $this->orderId = $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)))['order_id'];
    $this->request = buatPengajuan($this);
    $this->alamat = "/admin/{$this->pos->company->code}/pengembalian-dana";
});

afterEach(fn () => app(TenantContext::class)->reset());

/**
 * Baris pengajuan dibuat langsung supaya uji ini fokus pada layar back-office; jalur POS-nya sendiri
 * sudah dijaga tests/Feature/Sales/GatewayRefundTest.php.
 */
function buatPengajuan(object $test): GatewayRefundRequest
{
    return $test->pos->tenant(function () use ($test) {
        $order = Order::query()->findOrFail($test->orderId);
        $request = new GatewayRefundRequest;
        $request->forceFill([
            'id' => (string) Str::uuid7(),
            'company_id' => $order->company_id,
            'outlet_id' => $order->outlet_id,
            'order_id' => $order->id,
            'order_business_date' => $order->business_date->format('Y-m-d'),
            'business_date' => $order->business_date->format('Y-m-d'),
            'shift_id' => $test->shiftId,
            'amount' => '78500.00',
            'method' => 'qris',
            'lines' => [],
            'stock_action' => 'return',
            'reason' => 'Pesanan tidak sesuai',
            'requested_by' => $test->pos->userId('cashier'),
            'status' => GatewayRefundRequest::PENDING,
            'device_created_at' => now(),
            'server_received_at' => now(),
        ])->save();
        $order->forceFill(['flags' => [GatewayRefundRequest::ORDER_FLAG]])->save();

        return $request;
    });
}

/**
 * Masuk sebagai pengguna back-office.
 *
 * `forgetGuards()` wajib: beforeEach mendorong pesanan memakai token PERANGKAT, dan guard yang
 * masih memegang token itu membuat permintaan web dialihkan ke halaman masuk (302) — bukan 403
 * seperti yang sedang diuji. Panel & tenant dipasang eksplisit karena uji Livewire tidak melewati
 * middleware yang biasanya melakukannya.
 */
function masuk(object $test, User $user): void
{
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $test->actingAs($user, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($test->pos->company);
    app(TenantContext::class)->setTenant($test->pos->company->id);
}

function bukaDaftar(object $test, User $user)
{
    masuk($test, $user);
    $test->get($test->alamat)->assertSuccessful();
    // Permintaan HTTP di atas ikut membereskan konteks tenant saat selesai; uji Livewire tidak
    // melewati middleware yang memasangnya lagi, jadi harus dipasang ulang di sini.
    masuk($test, $user);

    return Livewire::test(ListGatewayRefundRequests::class);
}

it('menampilkan daftarnya untuk peran yang boleh melihat transaksi', function (string $role) {
    [$user] = Factory::staff($this->pos->company, [$role], [$this->pos->outlet->id]);

    masuk($this, $user);
    $this->get($this->alamat)->assertSuccessful();
})->with(['manajer outlet' => ['outlet_manager'], 'finance' => ['finance']]);

it('menutup alamatnya untuk peran yang tidak boleh melihat transaksi', function (string $role) {
    [$user] = Factory::staff($this->pos->company, [$role], [$this->pos->outlet->id]);

    masuk($this, $user);
    $this->get($this->alamat)->assertForbidden();
})->with(['kasir' => ['cashier'], 'dapur' => ['kitchen'], 'gudang' => ['warehouse']]);

it('menyembunyikan aksi penyelesaian dari manajer outlet', function () {
    /*
     * Manajer outlet boleh MENGAJUKAN dari POS dan boleh melihat antreannya, tetapi tidak boleh
     * menyatakan uangnya sudah berpindah — ia tidak memegang dashboard acquirer.
     */
    [$manajer] = Factory::staff($this->pos->company, ['outlet_manager'], [$this->pos->outlet->id]);

    bukaDaftar($this, $manajer)
        ->assertTableActionHidden('settle', $this->request)
        ->assertTableActionHidden('cancel', $this->request);
});

it('membuka aksi penyelesaian untuk finance', function () {
    [$finance] = Factory::staff($this->pos->company, ['finance'], [$this->pos->outlet->id]);

    bukaDaftar($this, $finance)->assertTableActionVisible('settle', $this->request);
});

it('mencatat retur lewat form ketika finance mengisi nomor referensi', function () {
    [$finance] = Factory::staff($this->pos->company, ['finance'], [$this->pos->outlet->id]);

    bukaDaftar($this, $finance)
        ->callTableAction('settle', $this->request, ['gateway_reference' => 'AINO-REF-77', 'note' => 'Dikembalikan lewat dashboard'])
        ->assertHasNoTableActionErrors();

    $selesai = $this->pos->tenant(fn () => GatewayRefundRequest::query()->findOrFail($this->request->id));
    expect($selesai->status)->toBe(GatewayRefundRequest::SETTLED)
        ->and($selesai->gateway_reference)->toBe('AINO-REF-77')
        ->and($selesai->resolved_by)->toBe($finance->id);

    $order = $this->pos->tenant(fn () => Order::query()->findOrFail($this->orderId));
    expect($this->pos->tenant(fn () => Refund::query()->count()))->toBe(1)
        ->and((string) $order->refunded_total)->toBe('78500.00')
        ->and($order->flags)->not->toContain(GatewayRefundRequest::ORDER_FLAG);
});

it('menolak form penyelesaian tanpa nomor referensi', function () {
    [$finance] = Factory::staff($this->pos->company, ['finance'], [$this->pos->outlet->id]);

    bukaDaftar($this, $finance)
        ->callTableAction('settle', $this->request, ['gateway_reference' => ''])
        ->assertHasTableActionErrors(['gateway_reference']);

    expect($this->pos->tenant(fn () => Refund::query()->count()))->toBe(0);
});

it('membatalkan pengajuan lewat form tanpa membuat retur', function () {
    [$finance] = Factory::staff($this->pos->company, ['finance'], [$this->pos->outlet->id]);

    bukaDaftar($this, $finance)
        ->callTableAction('cancel', $this->request, ['reason' => 'Pelanggan berubah pikiran'])
        ->assertHasNoTableActionErrors();

    $batal = $this->pos->tenant(fn () => GatewayRefundRequest::query()->findOrFail($this->request->id));
    expect($batal->status)->toBe(GatewayRefundRequest::CANCELLED)
        ->and($this->pos->tenant(fn () => Refund::query()->count()))->toBe(0);
});

it('tidak menampilkan pengajuan milik company lain', function () {
    $tetangga = Pos::setup('Warung Seberang', 'WSB');
    $orangLain = Factory::ownerOf($tetangga->company);

    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $this->actingAs($orangLain, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($tetangga->company);
    app(TenantContext::class)->setTenant($tetangga->company->id);
    Livewire::test(ListGatewayRefundRequests::class)->assertCanNotSeeTableRecords([$this->request]);

    // Dan kuerinya sendiri memang tidak memuat baris itu, bukan sekadar tidak dirender.
    expect(Factory::tenant($tetangga->company, fn () => GatewayRefundRequestResource::getEloquentQuery()->count()))->toBe(0);
});
