<?php

return [
    // Driver payment gateway untuk QRIS/e-wallet (ADR 0004). Mitra produksi belum dipilih (SRS §12.3 no. 1).
    'default' => env('PAYMENT_GATEWAY', 'sandbox'),

    /*
     * Masa berlaku tagihan QRIS/e-wallet (menit) — sekaligus BATAS ATAS: kedaluwarsa dari gateway
     * hanya dipakai bila lebih pendek daripada ini (lihat PaymentIntentService::charge).
     *
     * Diperpendek dari 15 ke 5 menit atas keputusan user 28 Sep 2026, setelah AINO mengirim
     * expiryDate yang terbaca tujuh jam ke depan. Selama zona waktu expiryDate belum dijelaskan
     * AINO, QR yang hidup lama adalah QR yang tidak bisa kami pertanggungjawabkan.
     */
    'intent_ttl_minutes' => (int) env('PAYMENT_INTENT_TTL', 5),

    'gateways' => [
        /*
         * AINO (PT AINO Indonesia) — QRIS MPM. Kredensial merchant TIDAK di sini: tiap outlet
         * punya merchant sendiri, jadi merchantcode & secretkey disimpan terenkripsi di tabel
         * `payment_gateway_accounts`. Yang di bawah ini hanya alamat & batas waktu.
         *
         * Perhatian: sandbox AINO memakai rel QRIS sungguhan — transaksi uji memindahkan uang
         * nyata. Jangan pernah memanggil gateway ini dari uji otomatis atau seeder.
         */
        'aino' => [
            'base_url' => [
                'sandbox' => env('PAYMENT_AINO_URL_SANDBOX', 'https://svc-core-go-dev.ainosi.com'),
                'production' => env('PAYMENT_AINO_URL_PRODUCTION', 'https://apg.ainosi.com'),
            ],

            // Dokumen AINO v1.0.0 menyebut dua alamat berbeda untuk Query Payment: `/payment/v1/inquiry`
            // di tabel spesifikasi, `/payment/v1/status` di contoh permintaan. Dibuat bisa diatur agar
            // koreksinya tidak menyentuh kode setelah AINO mengonfirmasi yang benar.
            'paths' => [
                'charge' => env('PAYMENT_AINO_PATH_CHARGE', '/payment/v1/request'),
                'inquiry' => env('PAYMENT_AINO_PATH_INQUIRY', '/payment/v1/inquiry'),
            ],

            // Dokumen menyebut "Expected Timeout 8 second".
            'timeout' => (int) env('PAYMENT_AINO_TIMEOUT', 8),

            // Biarkan kosong agar dirakit dari APP_URL. Isi hanya bila domainnya terlalu panjang
            // untuk batas 100 karakter milik AINO, atau saat mengarahkan callback ke staging.
            'callback_url' => env('PAYMENT_AINO_CALLBACK_URL'),
        ],

        'sandbox' => [
            // Kunci HMAC webhook sandbox. Wajib diisi di luar lingkungan lokal/uji.
            'webhook_secret' => env('PAYMENT_SANDBOX_SECRET'),
            // Endpoint simulasi bayar hanya untuk lingkungan local/testing (diperiksa juga di kode).
            'simulator_enabled' => (bool) env('PAYMENT_SANDBOX_SIMULATOR', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
        ],
    ],
];
