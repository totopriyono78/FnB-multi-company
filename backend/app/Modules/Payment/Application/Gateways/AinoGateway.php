<?php

namespace App\Modules\Payment\Application\Gateways;

use App\Modules\Payment\Application\GatewayAccounts;
use App\Modules\Payment\Application\QrisPayload;
use App\Modules\Payment\Domain\Models\PaymentGatewayAccount;
use App\Modules\Payment\Domain\Models\PaymentIntent;
use App\Modules\Sales\Application\SalesException;
use App\Modules\Tenancy\Application\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Driver AINO (PT AINO Indonesia) untuk QRIS MPM — dokumen "API INTEGRATION - QRIS MPM or VA"
 * v1.0.0, 8 Februari 2026. Ringkasan & daftar pertanyaan terbuka: claude/integrasi-pembayaran-qris-aino.md.
 *
 * Tiga hal yang membedakan driver ini dari SandboxGateway, semuanya disengaja:
 *
 * 1. **Callback AINO tidak bertanda tangan.** Tabel header Finish Notify hanya memuat Content-type —
 *    tidak ada Authorization, signature, atau daftar IP. Karena itu isi callback TIDAK PERNAH dipakai
 *    untuk menentukan status: `parseWebhook()` memakai callback semata-mata sebagai pemicu, lalu
 *    menanyakan status sebenarnya ke Query Payment. Alur resmi AINO sendiri (langkah 12 dokumen induk)
 *    memang menyuruh merchant memanggil Query Payment untuk status final, jadi ini bukan siasat.
 *    Bila AINO kelak menyediakan tanda tangan, pasang verifikasinya di `verifyWebhook()`.
 *
 * 2. **AINO tidak punya API pembatalan.** `cancel()` tidak bisa mematikan QR yang masih hidup, jadi ia
 *    hanya mengiyakan setelah QR lewat `expiryDate` milik AINO — saat itu QR-nya memang sudah mati.
 *    Karena `expires_at` tagihan kita diisi dari `expiryDate` AINO, keduanya jatuh tempo bersamaan.
 *
 * 3. **`order_id` yang dikirim adalah id payment intent, bukan id order kasir.** Dokumen tidak
 *    menjelaskan apa yang terjadi bila generate dipanggil dua kali dengan order_id sama (satu QR atau
 *    dua tagihan?). Satu order kasir bisa punya beberapa tagihan — yang lama kedaluwarsa lalu dibuat
 *    ulang — jadi memakai id intent membuat tiap percobaan unik dan pertanyaan itu tidak relevan.
 *
 * Nominal: AINO mengirim `amount` kembali di setiap respons, dan PaymentIntentService membandingkannya
 * dengan nominal tagihan kita — beda sedikit pun ditolak & dicatat (`payment.amount_mismatch`).
 * Jadi satuan rupiah tidak ditebak di sini, melainkan diperiksa pada transaksi sungguhan pertama.
 */
class AinoGateway implements PaymentGateway
{
    public const PROVIDER = 'aino';

    /** Batas panjang `callback` menurut dokumen (Variable, 100 max). */
    private const CALLBACK_MAX = 100;

    /** @var array<string, PaymentIntent|null> Intent hasil verifyWebhook, dipakai lagi oleh parseWebhook. */
    private array $webhookIntents = [];

    public function __construct(
        private readonly GatewayAccounts $accounts,
        private readonly TenantContext $context,
    ) {}

    public function name(): string
    {
        return self::PROVIDER;
    }

    public function createCharge(PaymentIntent $intent): GatewayCharge
    {
        $account = $this->accounts->require(self::PROVIDER, $intent->outlet_id);

        $response = $this->client($account)->post($this->path('charge'), [
            'payment_type' => 'qr',
            'transaction_details' => [
                'order_id' => $intent->id,
                'gross_amount' => $this->rupiah($intent),
            ],
            'callback' => $this->callbackUrl(),
        ]);

        $data = $this->body($response, 'Generate QRIS', $this->konteks($account, $intent, [
            'callback' => $this->callbackUrl(),
        ]));
        $reference = $this->pick($data, 'referenceNo', 'referenceNumber');
        $content = (string) ($data['paymentContent'] ?? '');

        if ($reference === '' || $content === '') {
            throw new RuntimeException('Respons Generate QRIS AINO tidak memuat referenceNo/paymentContent.');
        }

        /*
         * Payload diperiksa SEBELUM QR-nya sampai ke pelanggan. Gambar QR yang isinya bukan QRIS
         * tetap terbaca kamera, lalu ditolak aplikasi bank dengan "pastikan QR yang discan berlogo
         * QRIS" — pelanggan sudah terlanjur berdiri di depan kasir. Lebih baik gagal di sini.
         */
        if (($cacat = QrisPayload::reason($content)) !== null) {
            throw new RuntimeException("paymentContent dari AINO bukan payload QRIS yang sah: {$cacat}.");
        }

        return new GatewayCharge(
            $reference,
            $content,
            null,
            $data,
            $this->kedaluwarsa($data),
        );
    }

    /**
     * `expiryDate` dari AINO, ditemani pemeriksaan kewarasan.
     *
     * 28 Sep 2026 layar pelanggan menampilkan "berlaku 429:20 lagi" — tujuh jam lebih untuk QR
     * yang mestinya hidup beberapa menit. Tujuh jam persis selisih WIB dengan UTC, jadi kuat
     * dugaan `expiryDate` dikirim dalam waktu Jakarta tanpa penanda zona lalu kami baca sebagai
     * UTC. Dugaan itu TIDAK dipakai untuk menggeser jamnya — menebak zona waktu pada batas
     * berlaku alat pembayaran bukan urusan tebakan. Yang dilakukan: nilai mentahnya dicatat
     * supaya pertanyaannya bisa dijawab AINO dengan bukti, dan batas yang kami pakai tetap
     * dipangkas oleh TTL kami sendiri di PaymentIntentService (kami boleh memperpendek masa
     * berlaku yang kami tampilkan, tidak pernah memperpanjangnya).
     *
     * @param  array<string, mixed>  $data
     */
    private function kedaluwarsa(array $data): ?CarbonImmutable
    {
        $mentah = (string) ($data['expiryDate'] ?? '');
        if ($mentah === '') {
            return null;
        }

        $expiry = CarbonImmutable::parse($mentah);
        $ttl = (int) config('payments.intent_ttl_minutes', 15);

        if ($expiry->greaterThan(now()->addMinutes($ttl))) {
            Log::warning('expiryDate AINO jauh melampaui TTL kami; periksa zona waktunya ke AINO.', [
                'expiryDate_mentah' => $mentah,
                'terbaca_sebagai' => $expiry->toIso8601String(),
                'sekarang' => now()->toIso8601String(),
                'ttl_menit' => $ttl,
            ]);
        }

        return $expiry;
    }

    public function status(PaymentIntent $intent): GatewayStatus
    {
        if ($intent->provider_reference === null) {
            return new GatewayStatus(GatewayStatus::PENDING);
        }

        $account = $this->accounts->require(self::PROVIDER, $intent->outlet_id);

        /*
         * Inquiry aman diulang (tidak mengubah apa pun), jadi boleh dicoba lagi saat jaringan goyah.
         *
         * `throw: false` penting dan bukan pelonggaran: tanpa itu Laravel melempar RequestException
         * untuk setiap status 4xx/5xx demi memicu percobaan ulang, sehingga badan respons AINO tidak
         * pernah sampai ke body() — pesan galatnya jadi RequestException mentah yang badannya
         * dipotong 120 karakter, tanpa konteks permintaan. Percobaan ulang untuk 4xx pun sia-sia:
         * "Transaction not found" tidak akan berubah dalam 250 ms. Dengan throw: false, yang tetap
         * diulang hanyalah kegagalan koneksi — persis yang dimaksudkan.
         */
        $response = $this->client($account)->retry(2, 250, throw: false)->post($this->path('inquiry'), [
            'order_id' => $intent->id,
            'reference_no' => $intent->provider_reference,
        ]);

        $data = $this->body($response, 'Query Payment', $this->konteks($account, $intent));
        /*
         * Nama field statusnya `statusLabel`, BUKAN `transactionStatusDesc`.
         *
         * Ini bukan tebakan: 28 Sep 2026 log mencatat jawaban Query Payment AINO apa adanya —
         *   {"responseCode":"2004700","responseMessage":"Successful",
         *    "referenceNumber":"20260928467742328420","partnerReferenceNumber":"01a0e605-…",
         *    "amount":{"value":"200","currency":"IDR"},"statusLabel":"paid"}
         *
         * Dokumen v1.0.0 menyebut `transactionStatusDesc` di tabel Query Payment dan
         * `latestTransactionStatus` di kolom Partner Action; keduanya tidak muncul. Yang dikirim
         * adalah `statusLabel` — nama field milik Finish Notify — dengan kosakata yang sama
         * (pending/expired/paid/fail/canceled). Keduanya diterima di sini: bila AINO kelak
         * merapikan dokumennya dan mengirim `transactionStatusDesc`, driver ini tidak perlu diubah.
         */
        $desc = $this->pick($data, 'transactionStatusDesc', 'statusLabel');
        $status = $this->mapStatus($desc);

        /*
         * Status yang tidak dikenal BUKAN kegagalan — dan jawaban AINO-nya dicatat utuh.
         *
         * 28 Sep 2026 seorang pelanggan membayar QRIS sungguhan, lalu tagihannya tertandai
         * `failed` dan pesanannya tidak pernah tersimpan. Log tidak memuat apa pun, karena
         * jalur ini tidak pernah mencatat jawaban Query Payment yang berhasil. Dua hal yang
         * berubah karena kejadian itu, keduanya ada di sini:
         *
         * 1. Apa pun yang tidak kami kenali dicatat APA ADANYA, sehingga pertanyaan "AINO
         *    sebenarnya mengirim apa?" dijawab log, bukan dugaan. (Dokumen v1.0.0 memang
         *    bertentangan: tabel menyebut `transactionStatusDesc`, kolom Partner Action
         *    menyebut `latestTransactionStatus` berkode 00/01/02/05/07 — lihat §3 butir 5.)
         * 2. Hasilnya `pending`, bukan `failed`. Kami tidak tahu arti kodenya, dan menebak ke
         *    arah "gagal" menghancurkan transaksi yang sudah dibayar; menunggu tidak. Tagihan
         *    tetap kedaluwarsa sendiri saat waktunya habis, jadi ia tidak menggantung selamanya.
         *
         * Memetakan kode yang belum dijelaskan AINO menjadi "lunas" tetap TIDAK dilakukan:
         * menyatakan uang sudah masuk adalah wewenang mereka, bukan tebakan kami.
         */
        if ($status === null) {
            Log::warning('Query Payment AINO memakai status yang belum dikenali; tagihan dibiarkan pending.', [
                'transactionStatusDesc' => $desc,
                'respons' => Arr::except($data, ['paymentContent']),
            ]);
        }

        return new GatewayStatus(
            $status ?? GatewayStatus::PENDING,
            isset($data['amount']['value']) ? (string) $data['amount']['value'] : null,
            filled($data['paidTime'] ?? null) ? CarbonImmutable::parse((string) $data['paidTime']) : null,
        );
    }

    /**
     * AINO tidak menyediakan pembatalan. Yang bisa dijawab jujur hanyalah: "QR ini sudah mati sendiri
     * atau belum?" — true hanya bila `expiryDate` AINO sudah lewat dan uangnya belum masuk.
     */
    public function cancel(PaymentIntent $intent): bool
    {
        if ($this->status($intent)->status === GatewayStatus::PAID) {
            return false;
        }

        $expiry = $intent->provider_payload['expiryDate'] ?? null;
        if (! is_string($expiry) || $expiry === '') {
            // Tanpa expiryDate kita tidak tahu kapan QR-nya mati; jangan mengaku bisa membatalkan.
            return false;
        }

        return CarbonImmutable::parse($expiry)->isPast();
    }

    /**
     * Tanpa tanda tangan, satu-satunya saringan sebelum event disimpan adalah: referensinya memang
     * milik tagihan kita. Ini mencegah siapa pun membanjiri tabel webhook_events dengan isi karangan.
     * Bukan autentikasi — kebenaran statusnya diambil di parseWebhook().
     */
    public function verifyWebhook(Request $request): bool
    {
        $reference = $this->reference($request);
        if ($reference === '') {
            return false;
        }

        return $this->webhookIntent($reference) !== null;
    }

    public function parseWebhook(Request $request): GatewayNotification
    {
        $data = $request->json()->all();
        $reference = $this->reference($request);
        $intent = $this->webhookIntent($reference);

        if (! $intent instanceof PaymentIntent) {
            throw new RuntimeException('Callback AINO untuk referensi yang tidak dikenal.');
        }

        // Status & nominal diambil dari AINO lewat Query Payment, bukan dari isi callback.
        $status = $this->context->runAsTenant($intent->company_id, fn (): GatewayStatus => $this->status($intent));

        return new GatewayNotification(
            // AINO tidak mengirim id event. Kunci anti-duplikat dirakit dari referensi + status +
            // waktu order, sehingga callback yang sama persis hanya diproses sekali.
            $reference.':'.($data['statusCode'] ?? '?').':'.($data['orderDate'] ?? '?'),
            $reference,
            $status->status,
            $status->amount ?? (string) $intent->amount,
            $status->paidAt,
            $data,
        );
    }

    /** Alamat callback yang dikirim ke AINO. Dibatasi 100 karakter oleh dokumen. */
    public function callbackUrl(): string
    {
        $url = (string) (config('payments.gateways.aino.callback_url') ?: url('/api/v1/webhooks/payment/'.self::PROVIDER));

        if (mb_strlen($url) > self::CALLBACK_MAX) {
            throw new RuntimeException(
                'Alamat callback AINO '.mb_strlen($url).' karakter, melebihi batas '.self::CALLBACK_MAX.
                '. Pakai domain lebih pendek atau isi PAYMENT_AINO_CALLBACK_URL.'
            );
        }

        return $url;
    }

    private function client(PaymentGatewayAccount $account): PendingRequest
    {
        return Http::baseUrl($this->baseUrl($account))
            /*
             * Dirapikan di titik pakai, bukan hanya saat disimpan: kredensial hampir selalu
             * ditempel dari email atau chat, dan satu spasi ikut terbawa di ujung sudah cukup
             * membuat gateway menjawab "merchant tidak dikenal" — galat yang tampak seperti
             * kredensial salah. Tidak ada kredensial yang sah berawal/berakhir dengan spasi.
             */
            ->withBasicAuth(trim($account->merchant_code), trim($account->secret_key))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(3)
            ->timeout((int) config('payments.gateways.aino.timeout', 8));
    }

    private function baseUrl(PaymentGatewayAccount $account): string
    {
        $urls = config('payments.gateways.aino.base_url');
        $url = is_array($urls) ? ($urls[$account->environment] ?? null) : null;

        return is_string($url) && $url !== ''
            ? rtrim($url, '/')
            : throw new RuntimeException("Base URL AINO untuk lingkungan [{$account->environment}] belum diatur.");
    }

    private function path(string $key): string
    {
        // Dokumen v1.0.0 menyebut dua alamat berbeda untuk Query Payment (/inquiry di tabel,
        // /status di contoh), jadi alamatnya dibuat bisa diatur tanpa menyentuh kode.
        return (string) config("payments.gateways.aino.paths.{$key}");
    }

    /**
     * Nominal dalam rupiah bulat. Pecahan tidak ditebak-tebak — lebih baik gagal keras.
     *
     * Satuannya kini pasti rupiah penuh, bukan satuan terkecil: 28 Sep 2026 pembayaran Rp200
     * dijawab AINO dengan `"amount":{"value":"200","currency":"IDR"}`. Pertanyaan §3 butir 2
     * dokumen terjawab oleh transaksi sungguhan, bukan oleh pembacaan tabel.
     *
     * Galatnya SalesException, bukan RuntimeException: nominal berpecahan adalah soal
     * pengaturan pembulatan outlet, bukan gateway yang tidak bisa dihubungi. Pesan "gateway
     * tidak dapat dihubungi" untuk sebab ini akan mengirim orang memeriksa jaringan padahal
     * yang perlu diubah ada di layar Outlet. Relevan begitu pembulatan dimatikan untuk uji
     * coba nominal kecil: total Rp1 + PB1 10% = Rp1,10 dan tidak bisa dikirim ke AINO.
     */
    private function rupiah(PaymentIntent $intent): int
    {
        $amount = BigDecimal::of((string) $intent->amount);
        if ($amount->getScale() > 0 && ! $amount->isEqualTo($amount->toScale(0, RoundingMode::DOWN))) {
            throw new SalesException(
                'AMOUNT_NOT_WHOLE_RUPIAH',
                "Nominal {$amount} bukan rupiah bulat, sedangkan QRIS hanya menerima bilangan bulat. "
                .'Sesuaikan pembulatan outlet atau harga item.',
                422,
            );
        }

        return $amount->toScale(0, RoundingMode::DOWN)->toInt();
    }

    /**
     * Konteks permintaan yang ikut ditempel ke pesan galat.
     *
     * Alasannya konkret: 27 Sep 2026 AINO menjawab `4004701 Merchant config not found`. Pesan itu
     * benar tetapi tidak cukup untuk dilaporkan — AINO akan balik bertanya "merchant code mana, di
     * lingkungan mana, ke host mana?". Satu baris log sekarang menjawab semuanya, sehingga tidak
     * perlu satu putaran tanya-jawab tiap kali kredensial diganti.
     *
     * `secret_key` TIDAK PERNAH ikut. Merchant code ikut karena justru itu yang perlu dicocokkan
     * AINO, dan nilainya sudah tampil apa adanya di layar kredensial maupun audit log.
     *
     * @param  array<string, string>  $tambahan
     */
    private function konteks(PaymentGatewayAccount $account, PaymentIntent $intent, array $tambahan = []): string
    {
        $bagian = [
            'merchant='.trim($account->merchant_code),
            'lingkungan='.$account->environment,
            'host='.(parse_url($this->baseUrl($account), PHP_URL_HOST) ?: '?'),
            'order_id='.$intent->id,
        ];

        foreach ($tambahan as $nama => $nilai) {
            $bagian[] = "{$nama}={$nilai}";
        }

        return ' [kirim: '.implode(', ', $bagian).']';
    }

    /**
     * @param  string  $konteks  Ringkasan permintaan tanpa kredensial, ditempel ke setiap pesan galat.
     * @return array<string, mixed>
     */
    private function body(Response $response, string $label, string $konteks = ''): array
    {
        /*
         * Isi respons dibaca LEBIH DULU, bahkan saat status HTTP-nya galat. Versi pertama langsung
         * melempar "gagal (HTTP 400)" tanpa melihat badannya, padahal justru di situ AINO menaruh
         * responseCode & responseMessage yang menjelaskan field mana yang ditolak. Pesan tanpa
         * sebab memaksa orang menebak; 27 Sep 2026 itu menghabiskan satu putaran penuh.
         */
        /** @var array<string, mixed> $data */
        $data = is_array($response->json()) ? $response->json() : [];
        $code = (string) ($data['responseCode'] ?? '');
        $message = (string) ($data['responseMessage'] ?? '');

        // Kode AINO bergaya SNAP: 7 digit yang diawali status HTTP-nya. Selain 2xx berarti ditolak.
        if ($code !== '' && ! str_starts_with($code, '2')) {
            throw new RuntimeException("{$label} AINO ditolak: {$code} ".($message ?: 'tanpa keterangan').'.'.$konteks);
        }

        if ($response->failed()) {
            // Tidak ada responseCode yang bisa dibaca: sertakan cuplikan badan apa adanya supaya
            // penyebabnya tetap terlihat di log, bukan hanya angka status.
            $cuplikan = trim(mb_substr($response->body(), 0, 300));

            throw new RuntimeException(
                "{$label} AINO gagal (HTTP {$response->status()})".($cuplikan !== '' ? ": {$cuplikan}" : '.').$konteks
            );
        }

        if ($code === '') {
            throw new RuntimeException("{$label} AINO menjawab tanpa responseCode.".$konteks);
        }

        return $data;
    }

    /**
     * Dokumen memakai dua ejaan untuk field yang sama; terima keduanya.
     *
     * @param  array<string, mixed>  $data
     */
    private function pick(array $data, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return (string) $data[$key];
            }
        }

        return '';
    }

    /** @return string|null null bila kosakatanya belum dikenali — penelponnya yang memutuskan. */
    private function mapStatus(string $status): ?string
    {
        return match (mb_strtolower(trim($status))) {
            'paid', 'success', 'settlement' => GatewayStatus::PAID,
            'pending', 'process', 'processing' => GatewayStatus::PENDING,
            'expired', 'expire' => GatewayStatus::EXPIRED,
            'canceled', 'cancelled' => GatewayStatus::CANCELLED,
            'fail', 'failed' => GatewayStatus::FAILED,
            default => null,
        };
    }

    private function reference(Request $request): string
    {
        $data = $request->json()->all();

        return is_array($data) ? $this->pick($data, 'referenceNo', 'referenceNumber') : '';
    }

    /** Callback datang tanpa konteks tenant, jadi pencarian tagihannya memakai mode sistem. */
    private function webhookIntent(string $reference): ?PaymentIntent
    {
        if (array_key_exists($reference, $this->webhookIntents)) {
            return $this->webhookIntents[$reference];
        }

        /** @var PaymentIntent|null $intent */
        $intent = $this->context->runAsSystem(fn () => PaymentIntent::query()
            ->where('provider', self::PROVIDER)
            ->where('provider_reference', $reference)
            ->first());

        return $this->webhookIntents[$reference] = $intent;
    }
}
