<?php

use App\Modules\Payment\Application\Gateways\GatewayStatus;
use App\Modules\Payment\Application\Gateways\SandboxGateway;
use App\Modules\Payment\Domain\Models\PaymentIntent;
use App\Modules\Sales\Application\GatewayRefundService;
use App\Modules\Sales\Application\SalesException;
use App\Modules\Sales\Domain\Models\GatewayRefundRequest;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Refund;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\Factory;
use Tests\Support\Pos;

/**
 * Pengembalian dana transaksi berbayar gateway (keputusan user 30 Sep 2026).
 *
 * Yang dijaga di sini bukan sekadar "fitur berjalan", melainkan satu janji uang: **tidak ada retur
 * yang tercatat sebelum dananya benar-benar dikembalikan.** Sampai 30 Sep 2026 janji itu dilanggar
 * tanpa suara — kasir bisa meretur QRIS, sistem mencatatnya lunas, dan tidak ada perintah
 * pengembalian yang pernah dikirim ke siapa pun karena gateway kami belum punya API refund.
 *
 * Pesanan uji standar bernilai 78.500 dan dibayar penuh lewat QRIS.
 */
beforeEach(function () {
    $this->pos = Pos::setup();
    [$this->shiftId, $this->ymd] = $this->pos->openShift('cashier', '500000');
    $this->cashierToken = $this->pos->login('cashier');
    /*
     * Retur & pengajuan dikirim sebagai MANAJER, bukan kasir. Bukan demi kemudahan: keduanya
     * menuntut wewenang `pos.void`, dan kasir harus lewat PIN supervisor lebih dulu. Memakai
     * manajer membuat uji ini menguji aturan uangnya, bukan alur PIN yang sudah dijaga uji lain.
     */
    $this->managerToken = $this->pos->login('manager');
    $this->orderId = jualQris($this, 1);
});

afterEach(fn () => app(TenantContext::class)->reset());

/** Pesanan yang benar-benar dibayar lewat tagihan gateway, bukan sekadar berlabel 'qris'. */
function jualQris(object $test, int $seq): string
{
    $orderId = (string) Str::uuid7();

    $intent = $test->postJson('/api/v1/payments/qris',
        ['order_ref' => $orderId, 'method' => 'qris', 'amount' => '78500'],
        bearer($test->cashierToken))->assertCreated()->json('data');

    $row = app(TenantContext::class)->runAsSystem(fn () => PaymentIntent::query()->findOrFail($intent['id']));
    $webhook = app(SandboxGateway::class)->simulate((string) $row->provider_reference, GatewayStatus::PAID, '78500');
    $test->call('POST', '/api/v1/webhooks/payment/sandbox', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SANDBOX_SIGNATURE' => $webhook['signature'],
    ], $webhook['body'])->assertOk();

    $payload = $test->pos->order($test->shiftId, $test->pos->receipt($test->ymd, $seq), [
        'payments' => [['id' => (string) Str::uuid7(), 'method' => 'qris', 'amount' => '78500', 'payment_intent_id' => $intent['id'], 'created_at' => now()->toIso8601String()]],
    ]);

    return $test->pos->push('order', $payload, $orderId)['order_id'];
}

/** Kirim pengajuan pengembalian dana sebagaimana layar kasir melakukannya. */
function ajukan(object $test, array $extra = []): array
{
    // `$extra` ditaruh di KIRI: operator + mempertahankan kunci sisi kiri, jadi yang di kanan
    // hanya mengisi yang belum ada. Terbalik, nilai pengganti tidak akan pernah terpakai.
    return $test->postJson('/api/v1/pos/orders/'.$test->orderId.'/gateway-refund-requests', $extra + [
        'id' => (string) Str::uuid7(),
        'shift_id' => $test->shiftId,
        'amount' => '78500.00',
        'method' => 'qris',
        'stock_action' => 'return',
        'reason' => 'Pesanan tidak sesuai',
        'created_at' => now()->toIso8601String(),
    ], bearer($test->managerToken))->json();
}

function pengajuan(object $test): GatewayRefundRequest
{
    return $test->pos->tenant(fn () => GatewayRefundRequest::query()->firstOrFail());
}

function pesanan(object $test): Order
{
    return $test->pos->tenant(fn () => Order::query()->findOrFail($test->orderId));
}

it('menolak retur QRIS dari kasir, dan tidak mengubah apa pun', function () {
    /*
     * Ini uji terpenting di berkas ini. Sebelum 30 Sep 2026 permintaan yang sama persis BERHASIL:
     * baris refunds tertulis, refunded_total bertambah, status jadi "Diretur" — padahal uang
     * pelanggan tetap di rekening acquirer dan tidak ada siapa pun yang diberi tahu.
     */
    $response = $this->postJson('/api/v1/pos/orders/'.$this->orderId.'/refunds', [
        'id' => (string) Str::uuid7(),
        'shift_id' => $this->shiftId,
        'amount' => '78500.00',
        'method' => 'qris',
        'stock_action' => 'return',
        'reason' => 'Pesanan tidak sesuai',
        'created_at' => now()->toIso8601String(),
    ], bearer($this->managerToken));

    $response->assertStatus(422);
    expect($response->json('errors.0.code'))->toBe('REFUND_GATEWAY_UNSUPPORTED');

    $order = pesanan($this);
    expect($this->pos->tenant(fn () => Refund::query()->count()))->toBe(0)
        ->and((string) $order->refunded_total)->toBe('0.00')
        ->and($order->status)->toBe(Order::PAID);
});

it('mencatat pengajuan tanpa memindahkan uang, stok, atau angka laporan', function () {
    $hasil = ajukan($this);

    expect($hasil['data']['request_status'])->toBe('pending')
        ->and($hasil['data']['amount'])->toBe('78500.00');

    $request = pengajuan($this);
    expect($request->status)->toBe(GatewayRefundRequest::PENDING)
        ->and($request->method)->toBe('qris')
        ->and($request->refund_id)->toBeNull()
        ->and($request->gateway_reference)->toBeNull();

    // Tidak ada retur, tidak ada uang keluar, status transaksi tidak bergerak.
    $order = pesanan($this);
    expect($this->pos->tenant(fn () => Refund::query()->count()))->toBe(0)
        ->and((string) $order->refunded_total)->toBe('0.00')
        ->and($order->status)->toBe(Order::PAID)
        // Tandanya yang berubah — supaya pesanan ini terlihat di daftar "perlu ditinjau".
        ->and($order->flags)->toContain(GatewayRefundRequest::ORDER_FLAG);
});

it('menolak pengajuan untuk metode yang tidak dipakai membayar', function () {
    $hasil = ajukan($this, ['method' => 'ewallet']);

    expect($hasil['errors'][0]['code'])->toBe('REFUND_METHOD_INVALID');
    expect($this->pos->tenant(fn () => GatewayRefundRequest::query()->count()))->toBe(0);
});

it('mencegah retur tunai atas nilai yang sedang menunggu pengembalian dana', function () {
    /*
     * Jebakan yang paling mahal: pengajuan sudah dibuat, lalu kasir lain meretur tunai transaksi
     * yang sama. Bila pengajuan tidak ikut dihitung sebagai "sudah diklaim", uangnya keluar dua
     * kali — sekali dari laci sekarang, sekali lagi saat finance memproses pengajuannya.
     */
    ajukan($this);

    $response = $this->postJson('/api/v1/pos/orders/'.$this->orderId.'/refunds', [
        'id' => (string) Str::uuid7(),
        'shift_id' => $this->shiftId,
        'amount' => '78500.00',
        'method' => 'cash',
        'stock_action' => 'return',
        'reason' => 'Pesanan tidak sesuai',
        'created_at' => now()->toIso8601String(),
    ], bearer($this->managerToken));

    expect($response->json('errors.0.code'))->toBe('ORDER_NOT_REFUNDABLE');
    expect($this->pos->tenant(fn () => Refund::query()->count()))->toBe(0);
});

it('melahirkan retur sungguhan saat finance mencatat dananya sudah dikembalikan', function () {
    ajukan($this);
    $request = pengajuan($this);
    $finance = Factory::ownerOf($this->pos->company);

    $refund = $this->pos->tenant(fn () => app(GatewayRefundService::class)->settle($request, $finance, 'AINO-REF-991', 'Dikembalikan lewat dashboard'));

    expect((string) $refund->amount)->toBe('78500.00')
        ->and($refund->method)->toBe('qris')
        ->and($refund->shift_id)->toBeNull();

    $selesai = pengajuan($this);
    expect($selesai->status)->toBe(GatewayRefundRequest::SETTLED)
        ->and($selesai->gateway_reference)->toBe('AINO-REF-991')
        ->and($selesai->refund_id)->toBe($refund->id)
        ->and($selesai->resolved_by)->toBe($finance->id);

    // Baru sekarang angka pesanannya bergerak, dan tandanya dilepas.
    $order = pesanan($this);
    expect((string) $order->refunded_total)->toBe('78500.00')
        ->and($order->status)->toBe(Order::REFUNDED)
        ->and($order->flags)->not->toContain(GatewayRefundRequest::ORDER_FLAG);
});

it('menolak mencatat pengembalian tanpa nomor referensi gateway', function () {
    // Nomor referensi adalah satu-satunya bukti bahwa uangnya benar-benar dikirim. Tanpa itu,
    // "sudah dikembalikan" hanya pernyataan sepihak.
    ajukan($this);
    $request = pengajuan($this);
    $finance = Factory::ownerOf($this->pos->company);

    expect(fn () => $this->pos->tenant(fn () => app(GatewayRefundService::class)->settle($request, $finance, '   ')))
        ->toThrow(fn (SalesException $e) => expect($e->errorCode)->toBe('GATEWAY_REFERENCE_REQUIRED'));

    expect($this->pos->tenant(fn () => Refund::query()->count()))->toBe(0);
});

it('menolak menyelesaikan pengajuan dua kali', function () {
    ajukan($this);
    $request = pengajuan($this);
    $finance = Factory::ownerOf($this->pos->company);
    $this->pos->tenant(fn () => app(GatewayRefundService::class)->settle($request, $finance, 'AINO-REF-991'));

    expect(fn () => $this->pos->tenant(fn () => app(GatewayRefundService::class)->settle($request, $finance, 'AINO-REF-992')))
        ->toThrow(fn (SalesException $e) => expect($e->errorCode)->toBe('GATEWAY_REFUND_ALREADY_RESOLVED'));

    expect($this->pos->tenant(fn () => Refund::query()->count()))->toBe(1);
});

it('membatalkan pengajuan tanpa pernah melahirkan retur', function () {
    ajukan($this);
    $request = pengajuan($this);
    $finance = Factory::ownerOf($this->pos->company);

    $this->pos->tenant(fn () => app(GatewayRefundService::class)->cancel($request, $finance, 'Pelanggan berubah pikiran'));

    $batal = pengajuan($this);
    expect($batal->status)->toBe(GatewayRefundRequest::CANCELLED)
        ->and($batal->refund_id)->toBeNull();

    $order = pesanan($this);
    expect($this->pos->tenant(fn () => Refund::query()->count()))->toBe(0)
        ->and((string) $order->refunded_total)->toBe('0.00')
        ->and($order->flags)->not->toContain(GatewayRefundRequest::ORDER_FLAG);

    // Nilainya bebas lagi: pembatalan melepaskan klaimnya.
    expect(ajukan($this)['data']['request_status'])->toBe('pending');
});

it('tidak membocorkan pengajuan milik company lain', function () {
    ajukan($this);

    $tetangga = Pos::setup('Warung Seberang', 'WSB');
    expect(Factory::tenant($tetangga->company, fn () => GatewayRefundRequest::query()->count()))->toBe(0);

    // Dan token perangkat tetangga tidak bisa menyentuh transaksi kita.
    $response = $this->postJson('/api/v1/pos/orders/'.$this->orderId.'/gateway-refund-requests', [
        'id' => (string) Str::uuid7(),
        'shift_id' => $this->shiftId,
        'amount' => '78500.00',
        'method' => 'qris',
        'stock_action' => 'return',
        'reason' => 'Pesanan tidak sesuai',
        'created_at' => now()->toIso8601String(),
    ], bearer($tetangga->login('manager')));

    // Transaksi kita tidak terlihat sama sekali dari konteks tenant tetangga.
    expect($response->json('errors.0.code'))->toBe('ORDER_NOT_FOUND');
    expect($this->pos->tenant(fn () => GatewayRefundRequest::query()->count()))->toBe(1);
});
