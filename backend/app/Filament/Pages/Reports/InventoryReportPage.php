<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Support\ReportPage;
use App\Modules\Reporting\Application\InventoryReport;
use App\Modules\Reporting\Application\ReportAccess;

/** Ringkasan mutasi, waste, hasil opname, dan posisi stok (FR-RPT-06). */
class InventoryReportPage extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Inventory';

    protected static ?string $title = 'Laporan Inventory';

    protected static ?string $slug = 'laporan/inventory';

    protected static ?int $navigationSort = 6;

    protected static function kind(): string
    {
        return ReportAccess::INVENTORY;
    }

    protected function reportKey(): string
    {
        return 'inventory.'.($this->variant ?? 'movements');
    }

    protected function variants(): array
    {
        return InventoryReport::VIEWS;
    }

    protected function usesPeriod(): bool
    {
        return $this->variant !== 'stock';
    }
}
