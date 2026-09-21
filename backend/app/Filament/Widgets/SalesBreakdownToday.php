<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Reports\SalesReportPage;
use App\Filament\Support\SalesDashboard;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/** Menu terlaris, peringkat outlet, dan metode bayar hari ini (FR-RPT-01, FR-RPT-10). */
class SalesBreakdownToday extends Widget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected static string $view = 'filament.widgets.sales-breakdown-today';

    protected static ?string $pollingInterval = '60s';

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return SalesDashboard::canView();
    }

    /** @return array<string, mixed>|null */
    public function data(): ?array
    {
        return SalesDashboard::data($this->filters);
    }

    public function reportUrl(string $dimension, string $date): string
    {
        return SalesReportPage::getUrl(['dari' => $date, 'sampai' => $date, 'tampilan' => $dimension]);
    }
}
