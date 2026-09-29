<?php

namespace App\Providers;

use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
