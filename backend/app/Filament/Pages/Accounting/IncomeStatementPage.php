<?php

namespace App\Filament\Pages\Accounting;

use App\Modules\Accounting\Application\FinancialStatements;
use App\Modules\Reporting\Application\ReportTable;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;

/** Laporan Laba Rugi (FIN-01). */
class IncomeStatementPage extends AccountingReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Laba Rugi';

    protected static ?string $title = 'Laporan Laba Rugi';

    protected static ?string $slug = 'pembukuan/laba-rugi';

    protected static ?int $navigationSort = 6;

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
            DatePicker::make('to')->label('Sampai tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
        ])->columns(2)->statePath('');
    }

    protected function build(CarbonImmutable $from, CarbonImmutable $to): ?ReportTable
    {
        return app(FinancialStatements::class)->incomeStatement($from, $to);
    }
}
