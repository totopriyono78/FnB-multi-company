<?php

namespace App\Filament\Pages\Accounting;

use App\Modules\Accounting\Application\GeneralLedger;
use App\Modules\Reporting\Application\ReportTable;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;

/** Neraca saldo per periode (ACC-08). */
class TrialBalancePage extends AccountingReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Neraca Saldo';

    protected static ?string $title = 'Neraca Saldo';

    protected static ?string $slug = 'pembukuan/neraca-saldo';

    protected static ?int $navigationSort = 3;

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
            DatePicker::make('to')->label('Sampai tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
        ])->columns(2)->statePath('');
    }

    protected function build(CarbonImmutable $from, CarbonImmutable $to): ?ReportTable
    {
        return app(GeneralLedger::class)->trialBalance($from, $to);
    }
}
