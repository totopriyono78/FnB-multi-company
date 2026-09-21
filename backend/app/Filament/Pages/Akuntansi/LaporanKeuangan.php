<?php

namespace App\Filament\Pages\Akuntansi;

use App\Filament\Support\PrototypePage;

/**
 * PROTOTIPE tampilan modul Akuntansi (belum tersambung ke basis data).
 */
class LaporanKeuangan extends PrototypePage
{
    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationLabel = 'Laporan Keuangan';

    protected static ?string $title = 'Laporan Keuangan';

    protected static ?string $slug = 'akuntansi/laporan-keuangan';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.akuntansi.laporan-keuangan';

    /** @var list<string> */
    protected static array $about = [
        'Laporan disusun per badan usaha.',
        'Bentuk tabel, ekspor Excel/PDF, dan pengiriman terjadwal ke email memakai mesin laporan yang sudah berjalan di sistem ini.',
    ];
}
