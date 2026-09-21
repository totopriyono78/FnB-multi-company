<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Support\ReportPage;
use App\Modules\Reporting\Application\ReportAccess;
use App\Modules\Reporting\Application\SalesReport;

/** Laporan penjualan per periode, jam, outlet, brand, kategori, item, channel, kasir, dan metode bayar (FR-RPT-02, FR-RPT-10). */
class SalesReportPage extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Penjualan';

    protected static ?string $title = 'Laporan Penjualan';

    protected static ?string $slug = 'laporan/penjualan';

    protected static ?int $navigationSort = 1;

    protected static function kind(): string
    {
        return ReportAccess::SALES;
    }

    protected function reportKey(): string
    {
        return 'sales.'.($this->variant ?? 'day');
    }

    protected function variants(): array
    {
        return SalesReport::DIMENSIONS;
    }

    protected function variantLabel(): string
    {
        return 'Kelompokkan';
    }
}
