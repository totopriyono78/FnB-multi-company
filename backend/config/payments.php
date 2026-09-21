<?php

return [
    // Driver payment gateway untuk QRIS/e-wallet (ADR 0004). Mitra produksi belum dipilih (SRS §12.3 no. 1).
    'default' => env('PAYMENT_GATEWAY', 'sandbox'),

    // Masa berlaku tagihan QRIS/e-wallet (menit).
    'intent_ttl_minutes' => (int) env('PAYMENT_INTENT_TTL', 15),

    'gateways' => [
        'sandbox' => [
            // Kunci HMAC webhook sandbox. Wajib diisi di luar lingkungan lokal/uji.
            'webhook_secret' => env('PAYMENT_SANDBOX_SECRET'),
            // Endpoint simulasi bayar hanya untuk lingkungan local/testing (diperiksa juga di kode).
            'simulator_enabled' => (bool) env('PAYMENT_SANDBOX_SIMULATOR', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
        ],
    ],
];
