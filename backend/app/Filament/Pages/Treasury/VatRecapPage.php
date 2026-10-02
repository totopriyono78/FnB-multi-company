<?php

namespace App\Filament\Pages\Treasury;

use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Treasury\Application\VatRecapReport;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;

/**
 * Rekap PPN masukan & keluaran (TAX-02).
 *
 * Alat bantu penyusunan SPT, bukan SPT. Itu dikatakan di kaki laporannya, karena laporan pajak yang
 * terlihat resmi padahal bukan adalah cara tercepat membuat orang melaporkan angka yang belum
 * dicocokkan dengan bukti fakturnya.
 */
class VatRecapPage extends TreasuryReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationLabel = 'Rekap PPN';

    protected static ?string $title = 'Rekap PPN Masukan & Keluaran';

    protected static ?string $slug = 'hutang/rekap-ppn';

    protected static ?int $navigationSort = 11;

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
            DatePicker::make('to')->label('Sampai tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
        ])->columns(2)->statePath('');
    }

    protected function build(CarbonImmutable $from, CarbonImmutable $to): ?ReportTable
    {
        return app(VatRecapReport::class)->build($from, $to);
    }
}
