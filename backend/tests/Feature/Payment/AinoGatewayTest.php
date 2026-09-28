<?php

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Payment\Application\GatewayAccounts;
use App\Modules\Payment\Application\Gateways\AinoGateway;
use App\Modules\Payment\Application\PaymentIntentService;
use App\Modules\Payment\Domain\Models\PaymentGatewayAccount;
use App\Modules\Payment\Domain\Models\PaymentIntent;
use App\Modules\Payment\Domain\Models\WebhookEvent;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Factory;
use Tests\Support\Pos;
use Tests\Support\Qris;

/**
 * Driver AINO untuk QRIS MPM (dokumen v1.0.0, 8 Februari 2026).
 *
 * AINO TIDAK PERNAH dipanggil sungguhan di sini. Sandbox AINO tersambung ke rel QRIS nyata —
 * tim AINO menyatakan "bahkan sandbox juga bisa langsung dibayar dengan e-wallet riil" — jadi
 * satu panggilan sungguhan dari suite yang berjalan puluhan kali sehari berarti uang sungguhan
 * berpindah. Semua di bawah ini memakai Http::fake() dengan bentuk respons dari dokumen.
 */
const AINO_REF = '61120260208201224';

beforeEach(function () {
    config()->set('payments.default', 'aino');
    $this->pos = Pos::setup();
    [$this->shiftId] = $this->pos->openShift();
    $this->token = $this->pos->login('cashier');
    $this->orderRef = (string) Str::uuid7();

    $this->akun = akunAino($this->pos->company, $this->pos->outlet->id);
    app(GatewayAccounts::class)->forget();
});

function akunAino(Company $company, ?string $outletId, string $merchant = 'Gamatechno_test', string $secret = 'rahasia-merchant'): PaymentGatewayAccount
{
    return Factory::tenant($company, function () use ($company, $outletId, $merchant, $secret): PaymentGatewayAccount {
        $akun = new PaymentGatewayAccount;
        $akun->forceFill([
            'id' => (string) Str::uuid7(),
            'company_id' => $company->id,
            'outlet_id' => $outletId,
            'provider' => 'aino',
            'environment' => PaymentGatewayAccount::SANDBOX,
            'merchant_code' => $merchant,
            'secret_key' => $secret,
            'is_active' => true,
        ])->save();

        return $akun;
    });
}

/** Respons Generate QRIS seperti contoh di dokumen AINO. */
function balasanGenerate(int $amount = 78500, ?string $expiry = null): array
{
    return [
        'responseCode' => '2004700',
        'responseMessage' => 'Successful',
        'referenceNo' => AINO_REF,
        'partnerReferenceNo' => 'diisi-saat-uji',
        'expiryDate' => $expiry ?? now()->addMinutes(5)->toIso8601String(),
        'paymentType' => 'qr',
        'amount' => ['value' => $amount, 'currency' => 'IDR'],
        'additionalInfo' => [],
        // Payload QRIS sungguhan dari dokumen AINO — driver menolak yang bukan QRIS,
        // jadi respons tiruan pun harus memakai payload yang sah.
        'paymentContent' => Qris::CONTOH,
    ];
}

/** Respons Query Payment. `$status` memakai kosakata dokumen: paid | pending | fail. */
function balasanInquiry(string $status = 'paid', int $amount = 78500): array
{
    return [
        'responseCode' => '2005500',
        'responseMessage' => 'Successful',
        // Dokumen memakai dua ejaan; di sini sengaja ejaan yang ADA DI CONTOH, bukan di tabel.
        'referenceNumber' => AINO_REF,
        'partnerReferenceNumber' => 'diisi-saat-uji',
        'amount' => ['value' => $amount, 'currency' => 'IDR'],
        'transactionStatusDesc' => $status,
        'paidTime' => $status === 'paid' ? now()->toIso8601String() : null,
    ];
}

function buatTagihan(object $test, string $amount = '78500'): array
{
    return $test->postJson('/api/v1/payments/qris', ['order_ref' => $test->orderRef, 'method' => 'qris', 'amount' => $amount], bearer($test->token))
        ->assertCreated()
        ->json('data');
}

/** Finish Notify persis seperti yang dikirim AINO: tanpa Authorization, tanpa tanda tangan. */
function callbackAino(object $test, string $statusCode = '3', string $reference = AINO_REF): TestResponse
{
    return $test->postJson('/api/v1/webhooks/payment/aino', [
        'referenceNo' => $reference,
        'acquireReferenceNo' => 'ACQ-001',
        'orderId' => 'order-123',
        'orderDate' => '2026-02-08 20:20:24',
        'paymentType' => 'QRIS',
        'amount' => '78500',
        'statusCode' => $statusCode,
        'statusLabel' => ['1' => 'pending', '2' => 'expired', '3' => 'paid', '4' => 'fail', '5' => 'canceled'][$statusCode],
    ]);
}

it('membuat QRIS lewat AINO dengan bentuk permintaan sesuai dokumen', function () {
    Http::fake(['*/payment/v1/request' => Http::response(balasanGenerate())]);

    $tagihan = buatTagihan($this);

    expect($tagihan['provider'])->toBe('aino')
        ->and($tagihan['status'])->toBe('pending')
        ->and($tagihan['qr_string'])->toStartWith('000201');

    Http::assertSent(function ($request) use ($tagihan) {
        $body = $request->data();

        return $request->url() === 'https://svc-core-go-dev.ainosi.com/payment/v1/request'
            && $body['payment_type'] === 'qr'
            // order_id = id intent, bukan id order kasir: tiap percobaan bayar jadi unik.
            && $body['transaction_details']['order_id'] === $tagihan['id']
            // Rupiah bulat, bukan "78500.00" dan bukan satuan terkecil.
            && $body['transaction_details']['gross_amount'] === 78500
            && str_starts_with($body['callback'], 'http')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('Gamatechno_test:rahasia-merchant'));
    });
});

it('memakai kedaluwarsa dari AINO, bukan TTL kita sendiri', function () {
    // TTL bawaan 15 menit; AINO cuma memberi 5 menit. Menampilkan QR yang sudah mati di depan
    // kasir adalah bug yang mahal, jadi yang menang harus punya AINO.
    Http::fake(['*/payment/v1/request' => Http::response(balasanGenerate(expiry: now()->addMinutes(5)->toIso8601String()))]);

    $tagihan = buatTagihan($this);

    expect(now()->diffInMinutes($tagihan['expires_at']))->toBeGreaterThan(4.9)->toBeLessThan(5.1);
});

it('menolak callback yang referensinya bukan milik tagihan mana pun', function () {
    // Tanpa tanda tangan, saringan pertama adalah referensinya harus dikenal — supaya tabel
    // webhook_events tidak bisa dibanjiri isi karangan.
    callbackAino($this, reference: 'REF-KARANGAN')->assertStatus(401);

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('TIDAK menandai lunas hanya karena callback bilang lunas', function () {
    // Inti keamanan driver ini. Finish Notify AINO tidak bertanda tangan: siapa pun yang tahu
    // alamat callback bisa mengirim statusCode 3. Yang menentukan status hanyalah Query Payment.
    Http::fake([
        '*/payment/v1/request' => Http::response(balasanGenerate()),
        '*/payment/v1/inquiry' => Http::response(balasanInquiry('pending')),
    ]);
    $tagihan = buatTagihan($this);

    callbackAino($this, statusCode: '3')->assertOk();

    $tersimpan = app(TenantContext::class)->runAsSystem(fn () => PaymentIntent::query()->findOrFail($tagihan['id']));
    expect($tersimpan->status)->toBe(PaymentIntent::PENDING)
        ->and($tersimpan->paid_at)->toBeNull();
});

it('menandai lunas ketika Query Payment membenarkan callback', function () {
    Http::fake([
        '*/payment/v1/request' => Http::response(balasanGenerate()),
        '*/payment/v1/inquiry' => Http::response(balasanInquiry('paid')),
    ]);
    $tagihan = buatTagihan($this);

    callbackAino($this)->assertOk()->assertJsonPath('data.processed', true);

    $tersimpan = app(TenantContext::class)->runAsSystem(fn () => PaymentIntent::query()->findOrFail($tagihan['id']));
    expect($tersimpan->status)->toBe(PaymentIntent::PAID)
        ->and($tersimpan->paid_at)->not->toBeNull();
});

it('menolak pelunasan bila nominal dari AINO berbeda', function () {
    // Satuan `amount` AINO belum dikonfirmasi (rupiah penuh atau satuan terkecil). Kalau ternyata
    // beda, transaksi pertama harus GAGAL KERAS dan tercatat — bukan diam-diam dianggap lunas.
    Http::fake([
        '*/payment/v1/request' => Http::response(balasanGenerate()),
        '*/payment/v1/inquiry' => Http::response(balasanInquiry('paid', amount: 785)),
    ]);
    $tagihan = buatTagihan($this);

    callbackAino($this)->assertOk();

    $tersimpan = app(TenantContext::class)->runAsSystem(fn () => PaymentIntent::query()->findOrFail($tagihan['id']));
    expect($tersimpan->status)->toBe(PaymentIntent::FAILED);

    $dicatat = app(TenantContext::class)->runAsSystem(
        fn () => AuditLog::withoutGlobalScopes()->where('action', 'payment.amount_mismatch')->count()
    );
    expect($dicatat)->toBe(1);
});

it('memproses callback yang sama persis hanya sekali', function () {
    Http::fake([
        '*/payment/v1/request' => Http::response(balasanGenerate()),
        '*/payment/v1/inquiry' => Http::response(balasanInquiry('paid')),
    ]);
    buatTagihan($this);

    callbackAino($this)->assertOk()->assertJsonPath('data.processed', true);
    // AINO tidak mengirim id event; kuncinya dirakit dari referensi + status + waktu order.
    callbackAino($this)->assertOk()->assertJsonPath('data.duplicate', true);

    expect(WebhookEvent::query()->count())->toBe(1);
});

it('tidak mengaku bisa membatalkan QR yang masih hidup', function () {
    // AINO tidak punya API pembatalan. Mengaku "berhasil dibatalkan" berarti kasir mengira QR mati
    // padahal pelanggan masih bisa membayarnya.
    Http::fake([
        '*/payment/v1/request' => Http::response(balasanGenerate(expiry: now()->addMinutes(5)->toIso8601String())),
        '*/payment/v1/inquiry' => Http::response(balasanInquiry('pending')),
    ]);
    $tagihan = buatTagihan($this);

    $intent = app(TenantContext::class)->runAsSystem(fn () => PaymentIntent::query()->findOrFail($tagihan['id']));

    // Dijalankan dalam konteks tenant: tabel kredensial tertutup RLS di luar konteks.
    $batal = fn (): bool => Factory::tenant($this->pos->company, fn () => app(AinoGateway::class)->cancel($intent));

    expect($batal())->toBeFalse();

    // Setelah kedaluwarsa versi AINO lewat, QR-nya memang sudah mati → barulah boleh diiyakan.
    $this->travelTo(now()->addMinutes(6));
    expect($batal())->toBeTrue();
});

it('menolak transaksi bila outlet belum punya kredensial', function () {
    // Kredensial milik company lain tidak boleh terpakai, dan tidak ada kredensial bawaan global.
    Factory::tenant($this->pos->company, fn () => PaymentGatewayAccount::query()->delete());
    [$lain] = Factory::company('Warung Sebelah');
    $outletLain = Factory::outlet($lain);
    akunAino($lain, $outletLain->id);
    app(GatewayAccounts::class)->forget();

    Http::fake();

    $this->postJson('/api/v1/payments/qris', ['order_ref' => $this->orderRef, 'method' => 'qris', 'amount' => '78500'], bearer($this->token))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'GATEWAY_NOT_CONFIGURED');

    Http::assertNothingSent();
});

it('memakai kredensial tingkat company bila outlet belum punya sendiri', function () {
    Factory::tenant($this->pos->company, fn () => PaymentGatewayAccount::query()->delete());
    akunAino($this->pos->company, null, merchant: 'Merchant_Company', secret: 'kunci-company');
    app(GatewayAccounts::class)->forget();
    Http::fake(['*/payment/v1/request' => Http::response(balasanGenerate())]);

    buatTagihan($this);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('Merchant_Company:kunci-company')));
});

it('menyimpan secret merchant dalam bentuk terenkripsi', function () {
    // Dump basis data yang bocor tidak boleh langsung membocorkan kunci merchant.
    $mentah = app(TenantContext::class)->runAsSystem(
        fn () => (string) DB::table('payment_gateway_accounts')->where('id', $this->akun->id)->value('secret_key')
    );

    expect($mentah)->not->toBe('rahasia-merchant')->and($mentah)->not->toContain('rahasia-merchant');
    expect($this->akun->fresh()->secret_key)->toBe('rahasia-merchant');
});

it('melaporkan gateway tidak tersedia ketika AINO menolak permintaan', function () {
    Http::fake(['*/payment/v1/request' => Http::response([
        'responseCode' => '4044708', 'responseMessage' => 'Invalid Merchant',
    ])]);

    $this->postJson('/api/v1/payments/qris', ['order_ref' => $this->orderRef, 'method' => 'qris', 'amount' => '78500'], bearer($this->token))
        ->assertStatus(502)
        ->assertJsonPath('errors.0.code', 'GATEWAY_UNAVAILABLE');
});

it('menolak alamat callback yang melebihi batas 100 karakter AINO', function () {
    config()->set('payments.gateways.aino.callback_url', 'https://'.str_repeat('a', 90).'.example.test/callback');

    expect(fn () => app(AinoGateway::class)->callbackUrl())
        ->toThrow(RuntimeException::class, 'melebihi batas 100');
});

it('mengirim gambar QR siap tampil, bukan cuma teks payload', function () {
    // Payload QRIS tidak ada gunanya bagi pelanggan; yang dipindai adalah gambarnya. SVG-nya
    // dirender server agar layar kasir tidak butuh pustaka QR dan tidak butuh permintaan kedua.
    Http::fake(['*/payment/v1/request' => Http::response(balasanGenerate())]);

    $tagihan = buatTagihan($this);

    expect($tagihan['qr_svg'])->toStartWith('<svg ')
        // Quiet zone 4 modul di tiap sisi adalah syarat standar QR; tanpa itu banyak pemindai gagal.
        // 65 modul (QR versi 14 untuk payload 280 karakter) + quiet zone 4 modul di tiap sisi.
        ->and($tagihan['qr_svg'])->toContain('viewBox="0 0 73 73"')
        ->and($tagihan['qr_svg'])->toContain('fill="#fff"');
});

it('tidak lagi mengirim QR setelah tagihan tidak berlaku', function () {
    Http::fake([
        '*/payment/v1/request' => Http::response(balasanGenerate()),
        '*/payment/v1/inquiry' => Http::response(balasanInquiry('paid')),
    ]);
    $tagihan = buatTagihan($this);
    callbackAino($this)->assertOk();

    $terkini = $this->getJson('/api/v1/payments/'.$tagihan['id'], bearer($this->token))->assertOk()->json('data');

    expect($terkini['status'])->toBe('paid')
        ->and($terkini['qr_svg'])->toBeNull()
        ->and($terkini['qr_string'])->toBeNull();
});

it('menolak paymentContent yang bukan payload QRIS', function () {
    // Kejadian 27 Sep 2026: QR yang isinya bukan QRIS tetap terbaca kamera, lalu ditolak aplikasi
    // bank saat pelanggan sudah berdiri di depan kasir. Lebih baik gagal sebelum QR ditampilkan.
    Http::fake(['*/payment/v1/request' => Http::response(
        ['responseCode' => '2004700', 'responseMessage' => 'Successful', 'referenceNo' => AINO_REF]
        + ['paymentContent' => '00020101021226SANDBOXpalsu6304SBOX', 'expiryDate' => now()->addMinutes(5)->toIso8601String()]
    )]);

    $this->postJson('/api/v1/payments/qris', ['order_ref' => $this->orderRef, 'method' => 'qris', 'amount' => '78500'], bearer($this->token))
        ->assertStatus(502)
        ->assertJsonPath('errors.0.code', 'GATEWAY_UNAVAILABLE');

    expect(PaymentIntent::withoutGlobalScopes()->where('order_ref', $this->orderRef)->value('status'))
        ->toBe(PaymentIntent::FAILED);
});

it('menandai QR simulasi sebagai tidak bisa dibayar', function () {
    Http::fake(['*/payment/v1/request' => Http::response(balasanGenerate())]);

    expect(buatTagihan($this)['qr_payable'])->toBeTrue();
});

it('menyertakan alasan penolakan AINO di log, bukan cuma status HTTP', function () {
    // 27 Sep 2026: driver melempar "gagal (HTTP 400)" tanpa membaca badan respons, padahal di situ
    // AINO menjelaskan field mana yang salah. Pesan tanpa sebab memaksa orang menebak.
    Http::fake(['*/payment/v1/request' => Http::response([
        'responseCode' => '4004701', 'responseMessage' => 'Invalid Field Format',
    ], 400)]);

    $this->postJson('/api/v1/payments/qris', ['order_ref' => $this->orderRef, 'method' => 'qris', 'amount' => '78500'], bearer($this->token))
        ->assertStatus(502);

    $intent = PaymentIntent::withoutGlobalScopes()->where('order_ref', $this->orderRef)->firstOrFail();

    expect(fn () => Factory::tenant($this->pos->company, fn () => app(AinoGateway::class)->createCharge($intent)))
        ->toThrow(RuntimeException::class, '4004701 Invalid Field Format');
});

it('menyertakan cuplikan badan respons saat AINO galat tanpa responseCode', function () {
    Http::fake(['*/payment/v1/request' => Http::response('<html>502 Bad Gateway</html>', 502)]);

    $this->postJson('/api/v1/payments/qris', ['order_ref' => $this->orderRef, 'method' => 'qris', 'amount' => '78500'], bearer($this->token))
        ->assertStatus(502);

    $intent = PaymentIntent::withoutGlobalScopes()->where('order_ref', $this->orderRef)->firstOrFail();

    expect(fn () => Factory::tenant($this->pos->company, fn () => app(AinoGateway::class)->createCharge($intent)))
        ->toThrow(RuntimeException::class, '502 Bad Gateway');
});

it('merapikan spasi kredensial sebelum dikirim ke AINO', function () {
    // Kredensial hampir selalu ditempel dari email/chat; satu spasi ikut terbawa sudah cukup
    // membuat gateway menjawab "merchant tidak dikenal" — galat yang menyesatkan.
    Factory::tenant($this->pos->company, fn () => PaymentGatewayAccount::query()->delete());
    akunAino($this->pos->company, $this->pos->outlet->id, merchant: "  Gamatechno_test\n", secret: ' rahasia-merchant ');
    app(GatewayAccounts::class)->forget();

    Http::fake(['*/payment/v1/request' => Http::response(balasanGenerate())]);

    buatTagihan($this);

    Http::assertSent(fn ($request) => $request->hasHeader(
        'Authorization', 'Basic '.base64_encode('Gamatechno_test:rahasia-merchant')
    ));
});

it('menyertakan konteks permintaan pada galat AINO agar bisa dilaporkan tanpa tanya-jawab', function () {
    /*
     * 27 Sep 2026: AINO menjawab `4004701 Merchant config not found`. Pesan itu benar tetapi tidak
     * cukup dilaporkan — AINO akan balik bertanya merchant code mana, lingkungan mana, host mana.
     * Satu baris log harus menjawab semuanya.
     */
    Http::fake(['*/payment/v1/request' => Http::response([
        'responseCode' => '4004701', 'responseMessage' => 'Merchant config not found',
    ], 400)]);

    $this->postJson('/api/v1/payments/qris', ['order_ref' => $this->orderRef, 'method' => 'qris', 'amount' => '78500'], bearer($this->token))
        ->assertStatus(502);

    $intent = PaymentIntent::withoutGlobalScopes()->where('order_ref', $this->orderRef)->firstOrFail();

    $pesan = null;
    try {
        Factory::tenant($this->pos->company, fn () => app(AinoGateway::class)->createCharge($intent));
    } catch (RuntimeException $e) {
        $pesan = $e->getMessage();
    }

    expect($pesan)->toContain('Merchant config not found')
        ->toContain('merchant=Gamatechno_test')
        ->toContain('lingkungan='.PaymentGatewayAccount::SANDBOX)
        ->toContain('order_id='.$intent->id)
        ->toContain('callback=')
        ->toContain('host=');
});

it('tidak pernah membocorkan secret merchant di pesan galat', function () {
    // Konteks galat dibaca manusia lalu ditempel ke tiket/chat dukungan. Secret tidak boleh ikut.
    Http::fake([
        '*/payment/v1/request' => Http::response(['responseCode' => '4004701', 'responseMessage' => 'Merchant config not found'], 400),
        '*/payment/v1/inquiry' => Http::response(['responseCode' => '4005501', 'responseMessage' => 'Transaction not found'], 400),
    ]);

    $this->postJson('/api/v1/payments/qris', ['order_ref' => $this->orderRef, 'method' => 'qris', 'amount' => '78500'], bearer($this->token))
        ->assertStatus(502);

    $intent = PaymentIntent::withoutGlobalScopes()->where('order_ref', $this->orderRef)->firstOrFail();
    $intent->forceFill(['provider_reference' => AINO_REF])->saveQuietly();

    foreach (['createCharge', 'status'] as $metode) {
        $pesan = null;
        try {
            Factory::tenant($this->pos->company, fn () => app(AinoGateway::class)->{$metode}($intent));
        } catch (RuntimeException $e) {
            $pesan = $e->getMessage();
        }

        expect($pesan)->not->toBeNull()
            ->and($pesan)->not->toContain('rahasia-merchant');
    }
});

it('menyampaikan alasan penolakan Query Payment, bukan RequestException mentah', function () {
    /*
     * Percobaan ulang bawaan Laravel melempar RequestException untuk setiap 4xx demi memicu retry,
     * sehingga badan respons AINO tidak sampai ke pembaca respons kita — pesannya jadi mentah dan
     * terpotong 120 karakter, tanpa konteks permintaan. Cacat ini tersembunyi sampai uji kebocoran
     * secret memicunya.
     */
    Http::fake([
        '*/payment/v1/request' => Http::response(balasanGenerate()),
        '*/payment/v1/inquiry' => Http::response([
            'responseCode' => '4005501', 'responseMessage' => 'Transaction not found',
        ], 400),
    ]);

    buatTagihan($this);
    $intent = PaymentIntent::withoutGlobalScopes()->where('order_ref', $this->orderRef)->firstOrFail();

    expect(fn () => Factory::tenant($this->pos->company, fn () => app(AinoGateway::class)->status($intent)))
        ->toThrow(RuntimeException::class, 'Query Payment AINO ditolak: 4005501 Transaction not found');
});

it('TIDAK menandai gagal ketika status dari AINO belum dikenali', function () {
    /*
     * Kejadian 28 Sep 2026: pelanggan membayar QRIS sungguhan, tagihan tertandai `failed`,
     * pesanan tidak pernah tersimpan. Penyebabnya satu baris: kosakata status yang tidak ada di
     * daftar kami dipetakan ke "gagal". Kami tidak tahu arti kode itu — dan menebak ke arah gagal
     * menghancurkan transaksi yang sudah dibayar, sedangkan menunggu tidak menghancurkan apa pun.
     */
    Http::fake([
        '*/payment/v1/request' => Http::response(balasanGenerate()),
        '*/payment/v1/inquiry' => Http::response([
            'responseCode' => '2005500', 'responseMessage' => 'Successful',
            'referenceNo' => AINO_REF, 'amount' => ['value' => 78500, 'currency' => 'IDR'],
            // Dokumen menyebut kolom ini di bagian Partner Action, tetapi tidak di tabel response.
            'latestTransactionStatus' => '01',
        ]),
    ]);

    buatTagihan($this);
    $intent = PaymentIntent::withoutGlobalScopes()->where('order_ref', $this->orderRef)->firstOrFail();

    $status = Factory::tenant($this->pos->company, fn () => app(AinoGateway::class)->status($intent));

    expect($status->status)->toBe('pending');
});

it('mencatat jawaban AINO apa adanya saat statusnya belum dikenali', function () {
    // Tanpa ini, "AINO sebenarnya mengirim apa?" hanya bisa dijawab dengan dugaan — dan 28 Sep 2026
    // memang tidak ada satu baris pun di log untuk transaksi yang hilang itu.
    Log::spy();

    Http::fake([
        '*/payment/v1/request' => Http::response(balasanGenerate()),
        '*/payment/v1/inquiry' => Http::response([
            'responseCode' => '2005500', 'responseMessage' => 'Successful',
            'referenceNo' => AINO_REF, 'transactionStatusDesc' => 'kosakata-baru',
            'amount' => ['value' => 78500, 'currency' => 'IDR'],
        ]),
    ]);

    buatTagihan($this);
    $intent = PaymentIntent::withoutGlobalScopes()->where('order_ref', $this->orderRef)->firstOrFail();
    Factory::tenant($this->pos->company, fn () => app(AinoGateway::class)->status($intent));

    Log::shouldHaveReceived('warning')->withArgs(fn (string $pesan, array $ctx): bool => str_contains($pesan, 'belum dikenali')
        && $ctx['transactionStatusDesc'] === 'kosakata-baru'
        && isset($ctx['respons']['referenceNo'])
        // Payload QRIS adalah alat pembayaran; tidak ikut ke log.
        && ! array_key_exists('paymentContent', $ctx['respons']));
});

it('tetap menandai gagal ketika AINO memang menyebut gagal', function () {
    // Sikap "tidak dikenal = pending" tidak boleh melunak jadi "tidak pernah gagal".
    Http::fake([
        '*/payment/v1/request' => Http::response(balasanGenerate()),
        '*/payment/v1/inquiry' => Http::response(balasanInquiry('fail')),
    ]);

    buatTagihan($this);
    $intent = PaymentIntent::withoutGlobalScopes()->where('order_ref', $this->orderRef)->firstOrFail();

    expect(Factory::tenant($this->pos->company, fn () => app(AinoGateway::class)->status($intent))->status)
        ->toBe('failed');
});

it('tidak pernah memperpanjang masa berlaku QR melebihi TTL kita sendiri', function () {
    /*
     * 28 Sep 2026 layar pelanggan menghitung mundur "429:20 lagi" — tujuh jam, persis selisih WIB
     * dengan UTC. Apa pun sebab aslinya di sisi AINO, kami hanya boleh MEMPERPENDEK masa berlaku
     * yang kami tampilkan, tidak pernah memperpanjangnya.
     */
    config()->set('payments.intent_ttl_minutes', 15);
    Http::fake(['*/payment/v1/request' => Http::response(
        balasanGenerate(expiry: now()->addHours(7)->toIso8601String())
    )]);

    $tagihan = buatTagihan($this);

    expect(CarbonImmutable::parse($tagihan['expires_at'])->diffInMinutes(now()))
        ->toBeLessThanOrEqual(15);
});

it('tetap memakai kedaluwarsa gateway bila lebih pendek daripada TTL kita', function () {
    // Arah sebaliknya tidak boleh ikut hilang: QR yang mati dalam 5 menit tidak boleh ditampilkan
    // 15 menit, karena pelanggan akan memindai kode yang sudah mati di depan kasir.
    config()->set('payments.intent_ttl_minutes', 15);
    Http::fake(['*/payment/v1/request' => Http::response(
        balasanGenerate(expiry: now()->addMinutes(5)->toIso8601String())
    )]);

    $tagihan = buatTagihan($this);

    expect(CarbonImmutable::parse($tagihan['expires_at'])->diffInMinutes(now()))->toBeLessThanOrEqual(6);
});

it('mencatat ke log berkas ketika gateway menyebut nominal berbeda', function () {
    /*
     * Tersangka kedua kejadian 28 Sep 2026: satuan nominal (rupiah penuh vs satuan terkecil,
     * §3 butir 2). Penolakannya benar — menerima nominal yang tidak cocok adalah hal terakhir
     * yang boleh ditebak — tetapi sebelumnya jejaknya hanya ada di tabel audit, sehingga
     * pembacaan log tidak melihat apa pun.
     */
    Log::spy();

    Http::fake([
        '*/payment/v1/request' => Http::response(balasanGenerate()),
        '*/payment/v1/inquiry' => Http::response(balasanInquiry('paid', amount: 7850000)),
    ]);

    buatTagihan($this);
    $intent = PaymentIntent::withoutGlobalScopes()->where('order_ref', $this->orderRef)->firstOrFail();
    Factory::tenant($this->pos->company, fn () => app(PaymentIntentService::class)->refresh($intent));

    Log::shouldHaveReceived('error')->withArgs(fn (string $pesan, array $ctx): bool => str_contains($pesan, 'nominal berbeda')
        && $ctx['nominal_tagihan'] === '78500.00'
        && $ctx['nominal_gateway'] === '7850000');
});

/*
 * Pemulihan tagihan yang terlanjur gagal (keputusan user 28 Sep 2026).
 *
 * Dibangun setelah sebuah pembayaran QRIS sungguhan hilang: uang berpindah, pesanan tidak
 * tersimpan, dan tidak ada satu pun jalan di aplikasi untuk memperbaikinya. Yang memutuskan
 * tetap gateway — uji di bawah menjaga persis batas itu.
 */
function tagihanGagal(object $test): PaymentIntent
{
    /*
     * Jawaban inquiry dibaca dari `$test->inquiry` tiap kali dipanggil, bukan dipasang sekali.
     * Sebabnya perangkap Laravel yang sempat menjebak uji ini: `Http::fake()` kedua MENAMBAH
     * stub, tidak menggantinya, dan yang menang adalah stub yang terdaftar lebih dulu. Memakai
     * closure membuat skenario "gagal dulu, lalu gateway membenarkan" bisa ditulis apa adanya.
     */
    $test->inquiry = ['status' => 'fail', 'amount' => 78500];

    Http::fake([
        '*/payment/v1/request' => Http::response(balasanGenerate()),
        '*/payment/v1/inquiry' => fn () => Http::response(
            balasanInquiry($test->inquiry['status'], $test->inquiry['amount'])
        ),
    ]);

    buatTagihan($test);
    $intent = PaymentIntent::withoutGlobalScopes()->where('order_ref', $test->orderRef)->firstOrFail();
    Factory::tenant($test->pos->company, fn () => app(PaymentIntentService::class)->refresh($intent));

    return $intent->refresh();
}

/** Minta persetujuan supervisor lalu panggil endpoint pemeriksaan ulang. */
function periksaUlang(object $test, PaymentIntent $intent): TestResponse
{
    $auth = $test->pos->authorize('payment_recheck', 'manager', [
        'reference_id' => $intent->id,
        'amount' => (string) $intent->amount,
    ]);

    return $test->postJson('/api/v1/payments/'.$intent->id.'/recheck', [
        'reason' => 'Pelanggan menunjukkan bukti transfer',
        'authorization' => ['mode' => 'online', 'authorization_id' => $auth],
    ], bearer($test->token));
}

it('memulihkan tagihan gagal ketika gateway membenarkan pembayarannya', function () {
    $intent = tagihanGagal($this);
    expect($intent->status)->toBe('failed');

    $this->inquiry = ['status' => 'paid', 'amount' => 78500];

    periksaUlang($this, $intent)->assertOk()->assertJsonPath('data.status', 'paid');

    // `paid`, bukan `paid_late`: tagihan ini mati karena kekeliruan kami, bukan karena pelanggan
    // terlambat membayar — jadi pesanannya memang boleh diselesaikan.
    expect($intent->refresh()->status)->toBe('paid')
        ->and($intent->paid_at)->not->toBeNull()
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'payment.intent_recovered')->count())->toBe(1);
});

it('TIDAK memulihkan tagihan bila gateway tetap menyatakan belum lunas', function () {
    $intent = tagihanGagal($this);

    periksaUlang($this, $intent)->assertOk()->assertJsonPath('data.status', 'failed');

    // Pemeriksaannya sendiri tetap tercatat: "sudah diperiksa, gateway bilang belum" adalah
    // keterangan yang dibutuhkan kasir maupun pemeriksa.
    expect(AuditLog::withoutGlobalScopes()->where('action', 'payment.rechecked')->count())->toBe(1)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'payment.intent_recovered')->count())->toBe(0);
});

it('TIDAK memulihkan tagihan bila nominal dari gateway berbeda', function () {
    // Selisih nominal justru salah satu tersangka kejadian aslinya. Melonggarkan penjaga ini
    // akan mengubah cacat pembacaan menjadi kebocoran uang.
    $intent = tagihanGagal($this);

    $this->inquiry = ['status' => 'paid', 'amount' => 7850000];

    periksaUlang($this, $intent)->assertOk()->assertJsonPath('data.status', 'failed');

    expect(AuditLog::withoutGlobalScopes()->where('action', 'payment.amount_mismatch')->count())->toBeGreaterThan(0)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'payment.intent_recovered')->count())->toBe(0);
});

it('menolak pemeriksaan ulang tanpa otorisasi supervisor', function () {
    // Kasir biasa tidak boleh membuka jalur ini sendirian.
    $intent = tagihanGagal($this);
    $this->inquiry = ['status' => 'paid', 'amount' => 78500];

    $this->postJson('/api/v1/payments/'.$intent->id.'/recheck', ['reason' => 'coba-coba'], bearer($this->token))
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'AUTHORIZATION_REQUIRED');

    expect($intent->refresh()->status)->toBe('failed');
});

it('menolak otorisasi yang diberikan untuk aksi lain', function () {
    // Persetujuan void tidak boleh dipakai ulang untuk memulihkan pembayaran.
    $intent = tagihanGagal($this);
    $this->inquiry = ['status' => 'paid', 'amount' => 78500];

    $auth = $this->pos->authorize('discount', 'manager', ['reference_id' => $intent->id]);

    $this->postJson('/api/v1/payments/'.$intent->id.'/recheck', [
        'reason' => 'coba pakai otorisasi lain',
        'authorization' => ['mode' => 'online', 'authorization_id' => $auth],
    ], bearer($this->token))->assertStatus(403);

    expect($intent->refresh()->status)->toBe('failed');
});

it('tidak mengusik tagihan yang sudah lunas', function () {
    Http::fake([
        '*/payment/v1/request' => Http::response(balasanGenerate()),
        '*/payment/v1/inquiry' => Http::response(balasanInquiry('paid')),
    ]);
    buatTagihan($this);
    $intent = PaymentIntent::withoutGlobalScopes()->where('order_ref', $this->orderRef)->firstOrFail();
    Factory::tenant($this->pos->company, fn () => app(PaymentIntentService::class)->refresh($intent));

    periksaUlang($this, $intent->refresh())->assertOk()->assertJsonPath('data.status', 'paid');

    expect(AuditLog::withoutGlobalScopes()->where('action', 'payment.rechecked')->count())->toBe(0);
});

it('memakai TTL bawaan 5 menit', function () {
    // Keputusan user 28 Sep 2026, setelah AINO mengirim expiryDate yang terbaca tujuh jam ke depan.
    // QR yang hidup lama adalah QR yang belum bisa kami pertanggungjawabkan selama zona waktunya
    // belum dijelaskan. Dijaga di sini supaya perubahannya kelak adalah keputusan, bukan kelalaian.
    expect((int) config('payments.intent_ttl_minutes'))->toBe(5);
});

it('membaca status dari statusLabel seperti yang benar-benar dikirim AINO', function () {
    /*
     * Bentuk respons di bawah disalin apa adanya dari log produksi-uji 28 Sep 2026 — bukan dari
     * dokumen, yang di titik ini keliru. Dokumen v1.0.0 menyebut `transactionStatusDesc` (tabel)
     * dan `latestTransactionStatus` (Partner Action); yang dikirim server adalah `statusLabel`,
     * nama field milik Finish Notify. Pembayaran Rp200 sungguhan hilang gara-gara selisih ini.
     */
    Http::fake([
        '*/payment/v1/request' => Http::response(balasanGenerate(amount: 200)),
        '*/payment/v1/inquiry' => Http::response([
            'responseCode' => '2004700',
            'responseMessage' => 'Successful',
            'referenceNumber' => '20260928467742328420',
            'partnerReferenceNumber' => '01a0e605-e5dc-724d-b2b4-b8b5fcea0738',
            'amount' => ['value' => '200', 'currency' => 'IDR'],
            'statusLabel' => 'paid',
        ]),
    ]);

    buatTagihan($this, '200');
    $intent = PaymentIntent::withoutGlobalScopes()->where('order_ref', $this->orderRef)->firstOrFail();

    $status = Factory::tenant($this->pos->company, fn () => app(AinoGateway::class)->status($intent));

    // Nominalnya rupiah penuh — "200" untuk pembayaran Rp200, bukan satuan terkecil (§3 butir 2).
    expect($status->status)->toBe('paid')
        ->and($status->amount)->toBe('200');

    // Dan tagihannya benar-benar lunas lewat jalur polling yang dipakai layar kasir.
    $hasil = Factory::tenant($this->pos->company, fn () => app(PaymentIntentService::class)->refresh($intent));
    expect($hasil->status)->toBe('paid');
});

it('tetap membaca transactionStatusDesc bila AINO merapikan dokumennya', function () {
    // Dua ejaan diterima: perbaikan di sisi AINO tidak boleh membuat driver ini perlu diubah lagi.
    Http::fake([
        '*/payment/v1/request' => Http::response(balasanGenerate()),
        '*/payment/v1/inquiry' => Http::response([
            'responseCode' => '2005500', 'responseMessage' => 'Successful',
            'referenceNo' => AINO_REF, 'transactionStatusDesc' => 'paid',
            'amount' => ['value' => 78500, 'currency' => 'IDR'],
        ]),
    ]);

    buatTagihan($this);
    $intent = PaymentIntent::withoutGlobalScopes()->where('order_ref', $this->orderRef)->firstOrFail();

    expect(Factory::tenant($this->pos->company, fn () => app(AinoGateway::class)->status($intent))->status)
        ->toBe('paid');
});

it('menyebut pembulatan outlet, bukan gateway, ketika nominalnya berpecahan', function () {
    /*
     * Muncul begitu pembulatan dimatikan untuk menguji nominal kecil: Rp1 + PB1 10% = Rp1,10.
     * Sebelumnya galatnya jatuh ke "Payment gateway tidak dapat dihubungi" — mengirim orang
     * memeriksa jaringan padahal yang perlu diubah ada di layar Outlet.
     */
    Http::fake(['*/payment/v1/request' => Http::response(balasanGenerate())]);

    $this->postJson('/api/v1/payments/qris', [
        'order_ref' => $this->orderRef, 'method' => 'qris', 'amount' => '1.10',
    ], bearer($this->token))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'AMOUNT_NOT_WHOLE_RUPIAH');

    Http::assertNothingSent();
});
