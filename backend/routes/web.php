<?php

use App\Filament\Demo\DemoAccounts;
use App\Modules\Shared\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

/*
 * Aplikasi kasir versi web (FR-POS / FR-DEV).
 *
 * Halaman ini hanya mengirim berkas statis; seluruh datanya diambil dari API POS
 * yang sama dengan yang akan dipakai aplikasi Flutter: /devices/pair, /pos/auth/pin,
 * /pos/catalog, /pos/quotes, /pos/shifts, /pos/orders, /payments/qris.
 *
 * Tidak ada jalan pintas: perangkat harus dipasangkan dengan kode pairing dari
 * back-office, kasir harus login PIN, dan transaksi disimpan lewat endpoint resmi.
 *
 * Dimatikan dengan FNB_POS_WEB=false.
 */
Route::get('/pos', function () {
    abort_unless((bool) config('fnb.pos_web', true), 404);

    return view('pos.app', [
        // Hanya di lingkungan demo: PIN ditampilkan di bawah nama kasir agar peragaan lancar.
        'demoPins' => DemoAccounts::enabled() ? DemoAccounts::posPins() : [],
    ]);
})->name('pos.web');

Route::redirect('/kasir', '/pos');

/*
 * Foto menu & logo brand. Lihat MediaController untuk alasan rute ini ada
 * (ringkasnya: menggantikan symlink public/storage yang merepotkan di Windows & PaaS).
 */
Route::get('/media/{folder}/{file}', [MediaController::class, 'show'])
    ->where(['folder' => 'menu|logo', 'file' => '[A-Za-z0-9._-]+'])
    ->name('media.show');
