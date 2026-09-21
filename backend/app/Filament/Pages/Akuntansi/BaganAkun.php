<?php

namespace App\Filament\Pages\Akuntansi;

use App\Filament\Support\PrototypePage;

/**
 * PROTOTIPE tampilan modul Akuntansi (belum tersambung ke basis data).
 */
class BaganAkun extends PrototypePage
{
    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationLabel = 'Bagan Akun (COA)';

    protected static ?string $title = 'Bagan Akun (COA)';

    protected static ?string $slug = 'akuntansi/bagan-akun';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.akuntansi.bagan-akun';

    /** @var list<string> */
    protected static array $about = [
        'Bagan akun standar disusun sekali di level holding, lalu dibagikan ke setiap entitas.',
        'Cabang tidak dapat mengubah atau menghapus akun induk — hanya mengaktifkan/menonaktifkan dan menambah sub-akun pada rentang yang diizinkan.',
    ];
}
