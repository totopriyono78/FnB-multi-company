<?php

namespace App\Filament\Pages\Akuntansi;

use App\Filament\Support\PrototypePage;

/**
 * PROTOTIPE tampilan modul Akuntansi (belum tersambung ke basis data).
 */
class AsetSewa extends PrototypePage
{
    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Aset & Sewa';

    protected static ?string $title = 'Aset & Sewa';

    protected static ?string $slug = 'akuntansi/aset-sewa';

    protected static ?int $navigationSort = 7;

    protected static string $view = 'filament.pages.akuntansi.aset-sewa';

    /** @var list<string> */
    protected static array $about = [
        'Modul aset diminta karena ada sewa dan hutang aset.',
        'Penyusutan, akrual sewa, dan angsuran pembiayaan dihitung otomatis tiap bulan dan langsung menjadi jurnal.',
    ];
}
