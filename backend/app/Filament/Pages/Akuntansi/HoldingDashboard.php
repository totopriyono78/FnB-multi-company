<?php

namespace App\Filament\Pages\Akuntansi;

use App\Filament\Support\PrototypePage;

/**
 * PROTOTIPE tampilan modul Akuntansi (belum tersambung ke basis data).
 */
class HoldingDashboard extends PrototypePage
{
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Dashboard Holding';

    protected static ?string $title = 'Dashboard Holding';

    protected static ?string $slug = 'akuntansi/holding';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.akuntansi.holding-dashboard';

    /** @var list<string> */
    protected static array $about = [
        'Empat entitas contoh berjalan terpisah; angkanya dikumpulkan ke holding sebagai saldo ringkas, bukan dengan membuka akses data antar-entitas.',
        'Papan kelengkapan harian di bawah adalah kunci janji laporan keuangan tiap 2 hari: penjualan terjurnal, nota terverifikasi, dan bank terekonsiliasi.',
    ];
}
