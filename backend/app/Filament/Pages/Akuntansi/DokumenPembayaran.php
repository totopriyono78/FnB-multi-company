<?php

namespace App\Filament\Pages\Akuntansi;

use App\Filament\Support\PrototypePage;

/**
 * PROTOTIPE tampilan modul Akuntansi (belum tersambung ke basis data).
 */
class DokumenPembayaran extends PrototypePage
{
    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationLabel = 'SPPK & Advis';

    protected static ?string $title = 'SPPK & Advis';

    protected static ?string $slug = 'akuntansi/dokumen-pembayaran';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.akuntansi.dokumen';

    /** @var list<string> */
    protected static array $about = [
        'Inilah pengganti pemeriksaan nota dan bukti transfer yang hari ini manual: cabang mengajukan lengkap dengan foto, pusat memverifikasi dari satu antrian, dan jurnal terbentuk sendiri setelah dibayar.',
        'Dua pola kerja didukung: cabang yang punya staf finance mengisi kode akun sendiri, cabang tanpa staf finance cukup mengunggah foto nota.',
    ];
}
