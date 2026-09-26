<?php

return [
    // Tampilkan daftar akun demo di halaman login (tidak pernah aktif di produksi).
    'demo_login' => (bool) env('FNB_DEMO_LOGIN', false),

    // Layar prototipe modul Akuntansi (tampilan saja, data contoh).
    'prototype_accounting' => (bool) env('FNB_PROTOTYPE_ACCOUNTING', true),

    // Aplikasi kasir versi web di /pos (memakai API POS resmi).
    'pos_web' => (bool) env('FNB_POS_WEB', true),

    // Back-office pindah halaman tanpa memuat ulang CSS/JS (Filament SPA mode). Matikan bila ada
    // halaman yang bermasalah dengan navigasi Livewire.
    'spa' => (bool) env('FNB_SPA', true),

    'media' => [
        /*
         * Tempat menyimpan foto menu & logo brand. Bawaannya disk lokal `media`
         * (lihat config/filesystems.php). Untuk produksi multi-server atau PaaS dengan
         * filesystem ephemeral, isi FNB_MEDIA_DISK=s3 beserta kredensial AWS_*.
         *
         * Beralih ke s3 tidak mengubah kode sama sekali, tetapi butuh adapternya sekali:
         *     composer require league/flysystem-aws-s3-v3
         * Paket itu sengaja tidak ikut dipasang dari awal karena AWS SDK memakan ±900 MB —
         * beban yang tidak masuk akal untuk lingkungan yang menyimpan berkas di disk sendiri.
         */
        'disk' => env('FNB_MEDIA_DISK', 'media'),

        // Ukuran maksimum berkas yang boleh diunggah, sebelum diperkecil di server.
        'max_upload_kb' => (int) env('FNB_MEDIA_MAX_UPLOAD_KB', 8192),

        // Batas sisi gambar setelah diperkecil. Kartu menu di kasir hanya ±260 px lebar,
        // jadi 800x600 sudah lebih dari cukup dan hemat kuota di outlet berkoneksi lemah.
        'item_width' => 800,
        'item_height' => 600,
        'logo_width' => 512,
        'logo_height' => 512,
    ],

    'auth' => [
        'login_max_attempts' => (int) env('FNB_LOGIN_MAX_ATTEMPTS', 5),
        'login_lock_minutes' => (int) env('FNB_LOGIN_LOCK_MINUTES', 15),
        'pin_max_attempts' => (int) env('FNB_PIN_MAX_ATTEMPTS', 5),
        'pin_lock_minutes' => (int) env('FNB_PIN_LOCK_MINUTES', 15),
        'pin_min_length' => 4,
        'pin_max_length' => 6,
        // Cost bcrypt PIN (lebih rendah dari password demi kecepatan POS; dilindungi lockout & rate limit).
        'pin_hash_rounds' => (int) env('FNB_PIN_HASH_ROUNDS', 10),
    ],

    'devices' => [
        'pairing_code_ttl_minutes' => (int) env('FNB_PAIRING_CODE_TTL', 15),
        'offline_after_seconds' => 180,
    ],

    'subscription' => [
        'grace_days' => (int) env('FNB_SUBSCRIPTION_GRACE_DAYS', 7),
        'trial_days' => 14,
    ],

    'inventory' => [
        // Potong stok penjualan dijalankan setelah respons HTTP terkirim (SRS §7.3) agar sinkronisasi POS tetap cepat.
        // Di konsol (seeder, perintah, test) posting langsung dijalankan kecuali opsi ini diaktifkan.
        'defer_in_console' => false,
    ],
];
