<?php

namespace App\Filament\Pages\Akuntansi;

use App\Filament\Support\PrototypePage;

/**
 * PROTOTIPE tampilan modul Akuntansi (belum tersambung ke basis data).
 */
class JurnalBukuBesar extends PrototypePage
{
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Jurnal & Buku Besar';

    protected static ?string $title = 'Jurnal & Buku Besar';

    protected static ?string $slug = 'akuntansi/jurnal';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.akuntansi.jurnal';

    /** @var list<string> */
    protected static array $about = [
        'Jurnal tidak pernah diubah setelah diposting — koreksi dilakukan lewat jurnal balik yang merujuk jurnal asal.',
        'Sebagian besar jurnal terbentuk otomatis: dari tutup hari POS, pemakaian bahan, penyusutan, sewa, dan dokumen pembayaran.',
    ];
}
