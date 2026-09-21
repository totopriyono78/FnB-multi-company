<?php

namespace App\Filament\Pages;

use App\Filament\Support\ReportPage;
use App\Filament\Support\SalesDashboard;
use App\Filament\Widgets\DeviceHealth;
use App\Filament\Widgets\OperationalAlerts;
use App\Filament\Widgets\SalesBreakdownToday;
use App\Filament\Widgets\SalesByHourChart;
use App\Filament\Widgets\SalesToday;
use App\Modules\Reporting\Application\ReportAccess;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

/** Ringkasan: penjualan real-time (FR-RPT-01) dan hal yang perlu ditindaklanjuti. */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'Ringkasan';

    protected static ?string $navigationLabel = 'Ringkasan';

    public function filtersForm(Form $form): Form
    {
        $user = ReportPage::user();
        if ($user === null || ! SalesDashboard::canView()) {
            return $form->schema([]);
        }
        $access = app(ReportAccess::class);
        $brands = $access->brandOptions($user, ReportAccess::SALES);
        $outlets = $access->outletOptions($user, ReportAccess::SALES);
        if (count($outlets) <= 1) {
            return $form->schema([]);
        }

        return $form->schema([
            Select::make('brand_id')->label('Brand')->placeholder('Semua brand')->options($brands)
                ->hidden(count($brands) <= 1)
                ->afterStateUpdated(fn (callable $set) => $set('outlet_id', null)),
            Select::make('outlet_id')->label('Outlet')->placeholder('Semua outlet')
                ->options(fn (Get $get) => $access->outletOptions($user, ReportAccess::SALES, $get('brand_id'))),
        ]);
    }

    public function getWidgets(): array
    {
        return [SalesToday::class, SalesByHourChart::class, SalesBreakdownToday::class, OperationalAlerts::class, DeviceHealth::class];
    }

    public function getColumns(): int|string|array
    {
        return 1;
    }
}
