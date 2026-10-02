<?php

namespace App\Filament\Pages\Treasury;

use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Treasury\Application\CompletenessBoard;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;

/**
 * Papan kelengkapan entry (FIN-07).
 *
 * Layar yang dibuka sebelum menyusun laporan, bukan sesudah. Ia tidak menilai apa pun — ia hanya
 * menyebut hari mana yang datanya belum lengkap, supaya laporan keuangan tidak disusun dari hari
 * yang masih bisa berubah besok.
 */
class CompletenessPage extends TreasuryReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Papan Kelengkapan';

    protected static ?string $title = 'Papan Kelengkapan Entry';

    protected static ?string $slug = 'kas/kelengkapan';

    protected static ?int $navigationSort = 10;

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
            DatePicker::make('to')->label('Sampai tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
        ])->columns(2)->statePath('');
    }

    protected function build(CarbonImmutable $from, CarbonImmutable $to): ?ReportTable
    {
        return app(CompletenessBoard::class)->build($from, $to);
    }
}
