<?php

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Payment\Application\Gateways\GatewayStatus;
use App\Modules\Payment\Application\Gateways\SandboxGateway;
use App\Modules\Payment\Domain\Models\PaymentIntent;
use App\Modules\Payment\Domain\Models\WebhookEvent;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Pos;

beforeEach(function () {
    $this->pos = Pos::setup();
    [$this->shiftId, $this->ymd] = $this->pos->openShift();
    $this->token = $this->pos->login('cashier');
    $this->orderRef = (string) Str::uuid7();
});

function createIntent(object $test, string $amount = '78500', string $method = 'qris', ?string $orderRef = null): array
{
    return $test->postJson('/api/v1/payments/qris', ['order_ref' => $orderRef ?? $test->orderRef, 'method' => $method, 'amount' => $amount], bearer($test->token))
        ->assertCreated()
        ->json('data');
}

/** Kirim webhook sandbox bertanda tangan untuk tagihan tertentu. */
function sandboxWebhook(object $test, string $intentId, string $status = GatewayStatus::PAID, ?string $amount = null, ?string $signature = null): TestResponse
{
    $intent = app(TenantContext::class)->runAsSystem(fn () => PaymentIntent::query()->findOrFail($intentId));
    $webhook = app(SandboxGateway::class)->simulate((string) $intent->provider_reference, $status, $amount ?? (string) $intent->amount);

    return $test->call('POST', '/api/v1/webhooks/payment/sandbox', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SANDBOX_SIGNATURE' => $signature ?? $webhook['signature'],
    ], $webhook['body']);
}

function qrisOrder(Pos $pos, string $shiftId, string $receipt, string $intentId, string $orderId): array
{
    return $pos->order($shiftId, $receipt, [
        'payments' => [['id' => (string) Str::uuid7(), 'method' => 'qris', 'amount' => '78500', 'payment_intent_id' => $intentId, 'created_at' => now()->toIso8601String()]],
    ]) + ['id' => $orderId];
}

it('membuat tagihan QRIS dinamis yang berlaku 15 menit dan memakai ulang tagihan yang sama', function () {
    $intent = createIntent($this);

    expect($intent['status'])->toBe('pending')
        ->and($intent['provider'])->toBe('sandbox')
        ->and($intent['qr_string'])->toStartWith('000201')
        ->and($intent['amount'])->toBe('78500.00');
    expect(now()->diffInMinutes($intent['expires_at']))->toBeGreaterThan(14.9)->toBeLessThan(15.1);

    // Permintaan ulang untuk order & nominal sama → tagihan sama (pelanggan tidak memindai dua QR).
    expect(createIntent($this)['id'])->toBe($intent['id']);

    // Nominal berubah → tagihan lama dibatalkan.
    $changed = createIntent($this, '80000');
    expect($changed['id'])->not->toBe($intent['id']);
    expect($this->pos->tenant(fn () => PaymentIntent::query()->find($intent['id'])->status))->toBe('cancelled');
});

it('menolak metode yang tidak aktif di outlet', function () {
    // E-wallet nonaktif secara bawaan sampai mitra dipilih.
    $this->postJson('/api/v1/payments/qris', ['order_ref' => $this->orderRef, 'method' => 'ewallet', 'amount' => '1000'], bearer($this->token))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'PAYMENT_METHOD_INACTIVE');
});

it('webhook bertanda tangan valid menandai lunas dan diproses sekali (FR-PAY-04)', function () {
    $intent = createIntent($this);

    sandboxWebhook($this, $intent['id'], signature: 'palsu')->assertStatus(401)->assertJsonPath('errors.0.code', 'INVALID_SIGNATURE');
    expect(app(TenantContext::class)->runAsSystem(fn () => WebhookEvent::query()->count()))->toBe(0);

    $response = sandboxWebhook($this, $intent['id']);
    $response->assertOk()->assertJsonPath('data.processed', true);

    $this->getJson("/api/v1/payments/{$intent['id']}", bearer($this->token))
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.qr_string', null);

    // Event yang sama dikirim ulang oleh gateway → tidak diproses dua kali.
    $event = app(TenantContext::class)->runAsSystem(fn () => WebhookEvent::query()->sole());
    $replay = $this->call('POST', '/api/v1/webhooks/payment/sandbox', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SANDBOX_SIGNATURE' => hash_hmac('sha256', (string) json_encode($event->payload), hash('sha256', 'sandbox-webhook|'.config('app.key'))),
    ], (string) json_encode($event->payload));
    $replay->assertOk()->assertJsonPath('data.duplicate', true);
    expect($event->company_id)->toBe($this->pos->company->id)->and($event->result)->toBe('paid');
});

it('transaksi QRIS wajib merujuk tagihan lunas milik transaksi itu (FR-PAY-06)', function () {
    $intent = createIntent($this);
    $orderId = $this->orderRef;

    // Belum dibayar → boleh dikirim ulang nanti.
    $pending = $this->pos->push('order', qrisOrder($this->pos, $this->shiftId, $this->pos->receipt($this->ymd, 1), $intent['id'], $orderId), $orderId);
    expect($pending['error']['code'])->toBe('PAYMENT_PENDING')->and($pending['error']['retryable'])->toBeTrue();

    sandboxWebhook($this, $intent['id'])->assertOk();
    $accepted = $this->pos->push('order', qrisOrder($this->pos, $this->shiftId, $this->pos->receipt($this->ymd, 1), $intent['id'], $orderId), $orderId);
    expect($accepted['status'])->toBe('accepted');
    expect($this->pos->tenant(fn () => PaymentIntent::query()->find($intent['id'])->consumed_by_order_id))->toBe($orderId);

    // Tagihan yang sama untuk transaksi lain → ditolak (mencegah bayar ganda).
    $other = (string) Str::uuid7();
    $double = $this->pos->push('order', qrisOrder($this->pos, $this->shiftId, $this->pos->receipt($this->ymd, 2), $intent['id'], $other), $other);
    expect($double['error']['code'])->toBe('PAYMENT_INTENT_INVALID');

    // Nominal berbeda dari tagihan → ditolak.
    $third = (string) Str::uuid7();
    $intent2 = createIntent($this, '50000', orderRef: $third);
    sandboxWebhook($this, $intent2['id'])->assertOk();
    $mismatch = $this->pos->push('order', qrisOrder($this->pos, $this->shiftId, $this->pos->receipt($this->ymd, 3), $intent2['id'], $third), $third);
    expect($mismatch['error']['code'])->toBe('PAYMENT_INTENT_INVALID');
});

it('pembayaran setelah dibatalkan ditandai paid_late untuk refund manual', function () {
    $intent = createIntent($this);

    $this->postJson("/api/v1/payments/{$intent['id']}/cancel", [], bearer($this->token))
        ->assertOk()->assertJsonPath('data.status', 'cancelled');

    // Gateway sungguhan bisa saja tetap menerima pembayaran yang sedang diproses.
    sandboxWebhook($this, $intent['id'])->assertOk();

    expect($this->pos->tenant(fn () => PaymentIntent::query()->find($intent['id'])->status))->toBe('paid_late');
    expect($this->pos->tenant(fn () => AuditLog::query()->where('action', 'payment.paid_late')->count()))->toBe(1);
});

it('tagihan kedaluwarsa dibatalkan di gateway saat polling', function () {
    $intent = createIntent($this);
    $this->travel(16)->minutes();

    $this->getJson("/api/v1/payments/{$intent['id']}", bearer($this->token))
        ->assertOk()->assertJsonPath('data.status', 'expired');

    sandboxWebhook($this, $intent['id'])->assertOk();
    expect($this->pos->tenant(fn () => PaymentIntent::query()->find($intent['id'])->status))->toBe('paid_late');
});

it('nominal webhook yang berbeda menggagalkan tagihan', function () {
    $intent = createIntent($this);

    sandboxWebhook($this, $intent['id'], amount: '1000')->assertOk();

    expect($this->pos->tenant(fn () => PaymentIntent::query()->find($intent['id'])->status))->toBe('failed');
    expect($this->pos->tenant(fn () => AuditLog::query()->where('action', 'payment.amount_mismatch')->count()))->toBe(1);
});

it('simulator sandbox dan perintah artisan menandai lunas', function () {
    $intent = createIntent($this);
    $this->postJson("/api/v1/payments/{$intent['id']}/simulate", [], bearer($this->token))
        ->assertOk()->assertJsonPath('data.status', 'paid');

    $second = $this->postJson('/api/v1/payments/qris', ['order_ref' => (string) Str::uuid7(), 'method' => 'qris', 'amount' => '1000'], bearer($this->token))->json('data');
    $this->artisan('fnb:sandbox-pay', ['intent' => $second['id']])->expectsOutputToContain('paid')->assertSuccessful();
});

it('sandbox tidak dapat dipakai di produksi', function () {
    $intent = createIntent($this);
    app()->detectEnvironment(fn () => 'production');

    // Webhook tanpa kunci rahasia ditolak, simulator & pembuatan tagihan sandbox tidak tersedia.
    sandboxWebhook($this, $intent['id'])->assertStatus(401);
    $this->postJson("/api/v1/payments/{$intent['id']}/simulate", [], bearer($this->token))->assertNotFound();
    $this->postJson('/api/v1/payments/qris', ['order_ref' => (string) Str::uuid7(), 'method' => 'qris', 'amount' => '1000'], bearer($this->token))
        ->assertStatus(500);
});

it('tagihan perangkat lain dan company lain tidak dapat diakses', function () {
    $intent = createIntent($this);
    $other = Pos::setup('Warung Bu Ratna', 'WBR');
    $otherToken = $other->login('cashier');

    $this->getJson("/api/v1/payments/{$intent['id']}", bearer($otherToken))->assertNotFound();
    $this->postJson("/api/v1/payments/{$intent['id']}/cancel", [], bearer($otherToken))->assertNotFound();
    $this->postJson("/api/v1/payments/{$intent['id']}/simulate", [], bearer($otherToken))->assertNotFound();

    // Transaksi company lain tidak dapat memakai tagihan ini.
    [$otherShift, $otherYmd] = $other->openShift();
    $orderId = $this->orderRef;
    $payload = $other->order($otherShift, $other->receipt($otherYmd, 1), [
        'payments' => [['id' => (string) Str::uuid7(), 'method' => 'qris', 'amount' => '78500', 'payment_intent_id' => $intent['id'], 'created_at' => now()->toIso8601String()]],
    ]);
    expect($other->push('order', $payload, $orderId)['error']['code'])->toBe('PAYMENT_INTENT_INVALID');
});
