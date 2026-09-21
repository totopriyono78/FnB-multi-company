<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Support\ReportPage;
use App\Modules\Reporting\Application\ReportAccess;

/** Menu terlaris, tidak laku, dan analisis menu engineering (FR-RPT-03). */
class MenuEngineeringPage extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationLabel = 'Menu Terlaris';

    protected static ?string $title = 'Menu Terlaris & Menu Engineering';

    protected static ?string $slug = 'laporan/menu';

    protected static ?int $navigationSort = 2;

    protected static function kind(): string
    {
        return ReportAccess::SALES;
    }

    protected function reportKey(): string
    {
        return 'menu_engineering';
    }

    public function extraView(): ?string
    {
        return 'filament.pages.reports.menu-engineering-advice';
    }
}
