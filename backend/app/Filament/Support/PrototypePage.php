<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Pages\Page;

/**
 * Induk halaman PROTOTIPE modul Akuntansi.
 *
 * Keterangan "layar prototipe" tidak ditampilkan di badan halaman, melainkan
 * di balik tombol keterangan pada kepala halaman.
 */
abstract class PrototypePage extends Page
{
    protected static ?string $navigationGroup = 'Akuntansi (Prototipe)';

    /** @var list<string> Paragraf keterangan yang muncul saat tombol keterangan ditekan. */
    protected static array $about = [];

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Modul Akuntansi hanya untuk pemegang izin `accounting.view` — sesuai matriks izin
     * SRS Lampiran 12.1: role `finance` (kelola + lihat) dan `owner` (lihat saja).
     *
     * Kasir, manajer outlet, manajer brand, admin company, dapur, dan gudang tidak melihatnya
     * sama sekali. Pemeriksaan ini ikut menutup ALAMATNYA, bukan cuma menyembunyikan menu:
     * `canAccess()` dipakai Filament untuk mengotorisasi permintaan ke halaman, sehingga
     * mengetik URL-nya langsung tetap ditolak. Statusnya yang masih prototipe tidak mengubah
     * apa pun di sini — angka contoh pun tidak boleh bocor ke peran yang tidak berkepentingan.
     */
    public static function canAccess(): bool
    {
        return (bool) config('fnb.prototype_accounting', true)
            && MenuFields::user()?->can('accounting.view') === true;
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('keterangan')
                ->label('Keterangan layar')
                ->icon('heroicon-o-information-circle')
                ->iconButton()
                ->color('gray')
                ->extraAttributes(['title' => 'Keterangan layar'])
                ->modalHeading(static::$title ?? static::getNavigationLabel())
                ->modalIcon('heroicon-o-information-circle')
                ->modalWidth('lg')
                ->modalContent(view('filament.pages.akuntansi._about', [
                    'paragraphs' => array_merge(
                        ['Layar ini masih **prototipe tampilan**: angkanya data contoh yang konsisten dan belum tersambung ke basis data. Dipakai untuk menyepakati tampilan serta alur kerja sebelum dibangun.'],
                        static::$about,
                    ),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Tutup'),
        ];
    }
}
