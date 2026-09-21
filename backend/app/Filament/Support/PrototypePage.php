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
        return (bool) config('fnb.prototype_accounting', true);
    }

    public static function canAccess(): bool
    {
        return (bool) config('fnb.prototype_accounting', true);
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
