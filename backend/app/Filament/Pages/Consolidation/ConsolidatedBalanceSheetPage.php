<?php

namespace App\Filament\Pages\Consolidation;

use App\Modules\Consolidation\Application\ConsolidationReports;
use App\Modules\Consolidation\Domain\Models\ConsolidationRun;
use App\Modules\Reporting\Application\ReportTable;

/** Neraca konsolidasi (CON-07). */
class ConsolidatedBalanceSheetPage extends ConsolidationReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Neraca Konsolidasi';

    protected static ?string $title = 'Neraca Konsolidasi';

    protected static ?string $slug = 'konsolidasi/neraca';

    protected static ?int $navigationSort = 3;

    protected function buildFor(ConsolidationRun $run): ReportTable
    {
        return app(ConsolidationReports::class)->balanceSheet($run);
    }
}
