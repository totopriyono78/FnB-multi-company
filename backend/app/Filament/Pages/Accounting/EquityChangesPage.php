<?php

namespace App\Filament\Pages\Accounting;

use App\Modules\Accounting\Application\FinancialStatements;
use App\Modules\Reporting\Application\ReportTable;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;

/** Laporan Perubahan Ekuitas (FIN-04). */
class EquityChangesPage extends AccountingReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = 'Perubahan Ekuitas';

    protected static ?string $title = 'Laporan Perubahan Ekuitas';

    protected static ?string $slug = 'pembukuan/perubahan-ekuitas';

    protected static ?int $navigationSort = 9;

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
            DatePicker::make('to')->label('Sampai tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
        ])->columns(2)->statePath('');
    }

    protected function build(CarbonImmutable $from, CarbonImmutable $to): ?ReportTable
    {
        return app(FinancialStatements::class)->equityChanges($from, $to);
    }
}
