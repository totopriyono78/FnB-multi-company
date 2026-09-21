<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Support\ReportPage;
use App\Modules\Reporting\Application\ReportAccess;

/** Laba kotor per outlet: penjualan bersih dikurangi HPP (FR-RPT-07). */
class GrossProfitPage extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Laba Kotor';

    protected static ?string $title = 'Laporan Laba Kotor';

    protected static ?string $slug = 'laporan/laba-kotor';

    protected static ?int $navigationSort = 5;

    protected static function kind(): string
    {
        return ReportAccess::SALES;
    }

    protected function reportKey(): string
    {
        return 'gross_profit';
    }
}
