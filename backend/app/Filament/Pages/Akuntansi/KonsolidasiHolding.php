<?php

namespace App\Filament\Pages\Akuntansi;

use App\Filament\Support\PrototypePage;

/**
 * PROTOTIPE tampilan modul Akuntansi (belum tersambung ke basis data).
 */
class KonsolidasiHolding extends PrototypePage
{
    protected static ?string $navigationIcon = 'heroicon-o-squares-plus';

    protected static ?string $navigationLabel = 'Konsolidasi Holding';

    protected static ?string $title = 'Konsolidasi Holding';

    protected static ?string $slug = 'akuntansi/konsolidasi';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.akuntansi.konsolidasi';

    /** @var list<string> */
    protected static array $about = [
        'Konsolidasi dibuat dengan menarik saldo ringkas tiap entitas ke level holding — bukan dengan membuka akses data antar-entitas.',
        'Transaksi antar-entitas ditandai sejak jurnal dibuat sehingga dapat dieliminasi otomatis.',
    ];
}
