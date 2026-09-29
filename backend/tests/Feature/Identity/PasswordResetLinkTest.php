<?php

use App\Modules\Identity\Application\StaffManager;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Factory;

/**
 * Tautan reset password (cacat produksi 29 Sep 2026).
 *
 * Gejalanya: menambah staf baru di back-office → HTTP 500,
 * `RouteNotFoundException: Route [password.reset] not defined`.
 *
 * Sebabnya: `StaffManager::create()` dan `AuthController::forgotPassword()` memanggil
 * `Password::sendResetLink()` polos, sehingga yang dikirim adalah notifikasi BAWAAN Laravel —
 * dan notifikasi itu merakit alamatnya dengan `route('password.reset')`. Aplikasi ini tidak
 * punya rute bernama itu: autentikasi webnya dipegang panel Filament, yang memakai nama
 * rutenya sendiri. Alur reset dari halaman login Filament tetap jalan karena Filament
 * menimpa alamat notifikasinya sendiri — jadi cacat ini hanya muncul di dua jalur kami.
 *
 * Kenapa lolos dari 724 uji: `tests/TestCase.php` memanggil `Notification::fake()` untuk semua
 * uji, sehingga notifikasinya tidak pernah benar-benar dirakit. Uji di bawah karena itu
 * memanggil `toMail()` langsung — satu-satunya cara melihat kerusakannya.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
});

it('merakit tautan reset ke halaman Filament, bukan rute yang tidak ada', function () {
    $user = Factory::system(fn () => User::query()->findOrFail($this->owner->id));

    // Sebelum diperbaiki, baris inilah yang melempar RouteNotFoundException.
    $mail = (new ResetPassword('token-uji-123'))->toMail($user);

    expect($mail->actionUrl)->toContain('token-uji-123')
        ->and($mail->actionUrl)->toContain('password-reset/reset')
        // Alamatnya harus absolut agar bisa diklik dari email.
        ->and($mail->actionUrl)->toStartWith('http');
});

it('menyertakan email pada tautan agar formulir resetnya terisi', function () {
    $user = Factory::system(fn () => User::query()->findOrFail($this->owner->id));

    $mail = (new ResetPassword('token-uji-123'))->toMail($user);

    expect($mail->actionUrl)->toContain(urlencode($user->email));
});

it('menambah staf baru tanpa meledak saat notifikasinya dirakit', function () {
    /*
     * Jalur yang benar-benar dipakai user. `Notification::fake()` di TestCase dilepas khusus di
     * uji ini — justru pemalsuan itulah yang menyembunyikan cacatnya dari 724 uji lain, karena
     * notifikasinya tidak pernah sampai dirakit. Mail tetap dipalsukan supaya tidak ada surat
     * yang benar-benar dikirim.
     */
    Notification::clearResolvedInstances();
    Mail::fake();

    $member = Factory::tenant($this->company, fn () => app(StaffManager::class)->create($this->owner, [
        'name' => 'Kasir Baru',
        'email' => 'kasir.baru@contoh.test',
        'employee_code' => null,
        'roles' => ['cashier'],
        'scopes' => ['outlets' => [], 'brands' => []],
        'pin' => '135790',
    ]));

    expect($member->user_id)->not->toBeNull()
        ->and(User::query()->where('email', 'kasir.baru@contoh.test')->exists())->toBeTrue();
});
