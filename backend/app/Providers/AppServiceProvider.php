<?php

namespace App\Providers;

use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Semua alamat yang dirakit aplikasi memakai https bila APP_URL https.
         *
         * TrustProxies sudah dipasang di bootstrap/app.php, tetapi itu MIDDLEWARE: ia baru membaca
         * X-Forwarded-Proto setelah seluruh provider didaftarkan. Alamat apa pun yang lahir lebih
         * awal — favicon panel Filament pernah begitu (temuan tim penguji 29 Sep 2026) — masih
         * melihat request sebagai http:// dan diblokir peramban sebagai mixed content di halaman
         * https. Hal yang sama berlaku untuk pekerjaan antrean dan perintah artisan, yang tidak
         * punya request sama sekali.
         *
         * Ditaruh di register(), bukan boot(): provider ini terdaftar pertama (bootstrap/providers.php),
         * sehingga aturannya sudah berlaku sebelum panel Filament merakit apa pun.
         *
         * Bersyarat pada APP_URL supaya pengembangan lokal di http://127.0.0.1 tidak ikut dipaksa.
         */
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Tautan reset password diarahkan ke halaman milik panel Filament.
         *
         * Notifikasi bawaan Laravel merakit alamatnya dengan `route('password.reset')`, dan
         * aplikasi ini tidak punya rute bernama itu: autentikasi webnya dipegang panel Filament,
         * yang memakai nama rutenya sendiri. Akibatnya, sebelum baris ini ada, menambah staf baru
         * di back-office berakhir HTTP 500 — `Route [password.reset] not defined` (dilaporkan user
         * 29 Sep 2026), dan endpoint "lupa password" di API pun rusak dengan sebab yang sama.
         *
         * Diletakkan di sini, bukan di titik pemanggilan, supaya berlaku untuk SEMUA jalur yang
         * mengirim tautan reset — dua yang ada sekarang (StaffManager dan AuthController) dan
         * yang mungkin ditambahkan nanti. Alur reset dari halaman login Filament tidak terpengaruh:
         * Filament merakit notifikasinya sendiri dan menimpa alamatnya.
         */
        ResetPassword::createUrlUsing(
            fn (object $notifiable, string $token): string => Filament::getResetPasswordUrl($token, $notifiable)
        );
    }
}
