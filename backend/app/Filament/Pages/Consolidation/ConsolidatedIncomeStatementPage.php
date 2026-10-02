<?php

namespace App\Filament\Pages\Consolidation;

use App\Modules\Consolidation\Application\ConsolidationReports;
use App\Modules\Consolidation\Domain\Models\ConsolidationRun;
use App\Modules\Reporting\Application\ReportTable;

/** Laba Rugi konsolidasi (CON-07). */
class ConsolidatedIncomeStatementPage extends ConsolidationReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Laba Rugi Konsolidasi';

    protected static ?string $title = 'Laporan Laba Rugi Konsolidasi';

    protected static ?string $slug = 'konsolidasi/laba-rugi';

    protected static ?int $navigationSort = 4;

    protected function buildFor(ConsolidationRun $run): ReportTable
    {
        return app(ConsolidationReports::class)->incomeStatement($run);
    }
}
