<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "password ini bukan milik Anda" (keputusan user 29 Sep 2026).
 *
 * Sebelumnya password staf baru hanya bisa dibuat sendiri oleh stafnya lewat tautan email.
 * Cara itu menuntut SMTP yang berjalan, dan selama belum ada, staf baru tidak pernah bisa
 * masuk ke back-office sama sekali. Karena itu admin kini boleh memberi password awal.
 *
 * Konsekuensinya: untuk sesaat ada satu password yang diketahui dua orang. Kolom ini yang
 * menutup celah itu — selama bernilai true, panel menolak melayani halaman apa pun selain
 * halaman ganti password. Nilainya kembali false otomatis begitu passwordnya diubah
 * (lihat App\Modules\Identity\Domain\Models\User::booted), lewat jalur mana pun: halaman
 * profil, tautan reset, maupun artisan.
 *
 * Tidak berlaku untuk PIN kasir: PIN memang dibagikan admin dan dipakai di layar bersama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('must_change_password');
        });
    }
};
