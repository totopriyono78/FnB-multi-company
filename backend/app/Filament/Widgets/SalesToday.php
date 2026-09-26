<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Reports\SalesReportPage;
use App\Filament\Support\SalesDashboard;
use App\Modules\Reporting\Application\ReportTable;
use Carbon\CarbonImmutable;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Penjualan hari ini dengan pembanding hari yang sama minggu lalu sampai jam yang sama (FR-RPT-01). */
class SalesToday extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    /** Dirender bersama halaman: satu request, bukan satu request per widget (lebih ringan di server satu proses). */
    protected static bool $isLazy = false;

    protected static ?int $sort = 0;

    protected static ?string $pollingInterval = '60s';

    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return SalesDashboard::canView();
    }

    protected function getHeading(): ?string
    {
        return 'Penjualan hari ini';
    }

    protected function getDescription(): ?string
    {
        $data = SalesDashboard::data($this->filters);
        if ($data === null) {
            return null;
        }
        $date = CarbonImmutable::parse($data['business_date'])->locale('id');

        return 'Hari bisnis '.$date->translatedFormat('l, j F Y').'. Dibandingkan dengan '.$date->subWeek()->translatedFormat('l, j F').' sampai jam yang sama.';
    }

    protected function getStats(): array
    {
        $data = SalesDashboard::data($this->filters);
        if ($data === null) {
            return [];
        }
        $today = $data['today'];
        $url = SalesReportPage::getUrl(['dari' => $data['business_date'], 'sampai' => $data['business_date'], 'tampilan' => 'hour']);

        return [
            $this->stat('Penjualan bersih', ReportTable::rupiah($today['net_sales']), $data['change']['net_sales'], $data['same_time_last_week']['net_sales'], true)
                ->icon('heroicon-o-banknotes')->extraAttributes(['class' => 'fnb-stat--primary'])->url($url),
            $this->stat('Transaksi', ReportTable::number((string) $today['order_count']), $data['change']['order_count'], (string) $data['same_time_last_week']['order_count'], false)
                ->icon('heroicon-o-receipt-percent')->extraAttributes(['class' => 'fnb-stat--info']),
            $this->stat('Rata-rata per transaksi', ReportTable::rupiah($today['average_ticket']), $data['change']['average_ticket'], $data['same_time_last_week']['average_ticket'], true)
                ->icon('heroicon-o-calculator')->extraAttributes(['class' => 'fnb-stat--warning']),
            Stat::make('Kemarin (satu hari)', ReportTable::rupiah($data['yesterday']['net_sales']))
                ->description(ReportTable::number((string) $data['yesterday']['order_count']).' transaksi')
                ->icon('heroicon-o-calendar-days')->extraAttributes(['class' => 'fnb-stat--danger']),
        ];
    }

    private function stat(string $label, string $value, ?string $change, string $previous, bool $money): Stat
    {
        $prev = $money ? ReportTable::rupiah($previous) : ReportTable::number($previous);
        if ($change === null) {
            return Stat::make($label, $value)->description("Minggu lalu: {$prev}");
        }
        $up = ! str_starts_with($change, '-');
        $text = ($up ? 'Naik ' : 'Turun ').ReportTable::number(ltrim($change, '-'), 1).'% dari '.$prev;

        return Stat::make($label, $value)
            ->description($text)
            ->descriptionIcon($up ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down', IconPosition::Before)
            ->color($up ? 'success' : 'danger');
    }
}
