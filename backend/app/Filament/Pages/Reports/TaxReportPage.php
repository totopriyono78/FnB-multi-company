<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Support\ReportPage;
use App\Modules\Reporting\Application\ReportAccess;

/** Pajak daerah (PB1/PBJT) dan service charge per outlet (FR-RPT-05). */
class TaxReportPage extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Pajak & Service';

    protected static ?string $title = 'Laporan Pajak & Service Charge';

    protected static ?string $slug = 'laporan/pajak';

    protected static ?int $navigationSort = 4;

    protected static function kind(): string
    {
        return ReportAccess::SALES;
    }

    protected function reportKey(): string
    {
        return 'tax';
    }
}
