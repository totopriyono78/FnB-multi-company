<?php

namespace App\Filament\Widgets;

use App\Filament\DesignTokens;
use App\Filament\Support\SalesDashboard;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** Penjualan bersih per jam hari ini vs hari yang sama minggu lalu (FR-RPT-01). */
class SalesByHourChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected static ?string $heading = 'Penjualan per jam';

    protected static ?string $description = 'Penjualan bersih hari ini dibanding hari yang sama minggu lalu.';

    protected static ?string $pollingInterval = '60s';

    protected static ?string $maxHeight = '260px';

    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return SalesDashboard::canView();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = SalesDashboard::data($this->filters)['hourly'] ?? [];

        return [
            'datasets' => [
                [
                    'label' => 'Hari ini',
                    'data' => array_map(fn (array $r) => (float) $r['net_sales'], $rows),
                    'backgroundColor' => DesignTokens::PRIMARY,
                    'borderRadius' => 3,
                ],
                [
                    'label' => 'Minggu lalu',
                    'data' => array_map(fn (array $r) => (float) $r['last_week'], $rows),
                    'backgroundColor' => DesignTokens::NEUTRAL_MUTED,
                    'borderRadius' => 3,
                ],
            ],
            'labels' => array_map(fn (array $r) => $r['label'], $rows),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => [
                'x' => ['grid' => ['display' => false]],
                'y' => ['beginAtZero' => true, 'grid' => ['color' => DesignTokens::NEUTRAL_LINE]],
            ],
        ];
    }
}
