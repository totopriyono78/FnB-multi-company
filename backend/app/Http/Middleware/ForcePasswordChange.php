<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Domain\Models\User;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menahan pengguna di halaman profil selama passwordnya masih buatan admin (keputusan user
 * 29 Sep 2026, menyertai kolom `users.must_change_password`).
 *
 * Password yang diberikan admin diketahui dua orang sejak detik pertama: yang memberi dan yang
 * menerima. Selama itu belum diganti, setiap jejak di audit log atas nama staf tersebut bisa
 * dibantah — "bukan saya, atasan saya juga tahu passwordnya". Middleware ini mempersempit
 * jendela itu menjadi satu langkah pertama setelah masuk, bukan sekadar imbauan yang boleh
 * diabaikan.
 *
 * Halaman profil sendiri dan tombol keluar tentu dikecualikan; tanpa itu penggunanya terkurung.
 * Permintaan Livewire tidak perlu dikecualikan: rutenya berada di luar panel, sehingga formulir
 * di halaman profil tetap dapat disimpan.
 *
 * Tidak menyentuh API maupun layar kasir. Kasir masuk dengan PIN, dan menghentikan penjualan
 * gara-gara password back-office yang belum diganti jelas tidak sepadan.
 */
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $profile = Filament::getProfileUrl();

        if (! $user instanceof User || ! $user->must_change_password || $profile === null) {
            return $next($request);
        }

        if ($request->fullUrlIs($profile.'*') || $request->routeIs(Filament::getCurrentPanel()?->generateRouteName('auth.logout') ?? '')) {
            return $next($request);
        }

        Notification::make()
            ->warning()
            ->title('Buat password Anda sendiri dulu')
            ->body('Password yang sekarang dibuatkan admin dan masih diketahuinya. Ganti dulu sebelum memakai aplikasi.')
            ->persistent()
            ->send();

        return redirect()->to($profile);
    }
}
