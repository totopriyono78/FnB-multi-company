<?php

namespace App\Modules\Reporting\Application;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Daftar laporan yang dapat ditampilkan, diekspor, dan dijadwalkan. Kunci laporan dipakai di API
 * (`/reports/{key}`), ekspor, dan jadwal email.
 */
class ReportCatalog
{
    public function __construct(private readonly Container $app) {}

    /** @return array<string, array{label: string, group: string, kind: string}> */
    public static function all(): array
    {
        $out = [];
        foreach (SalesReport::DIMENSIONS as $dim => $label) {
            $out['sales.'.$dim] = ['label' => 'Penjualan — '.mb_strtolower($label), 'group' => 'Penjualan', 'kind' => ReportAccess::SALES];
        }
        $out['menu_engineering'] = ['label' => 'Menu terlaris & menu engineering', 'group' => 'Penjualan', 'kind' => ReportAccess::SALES];
        $out['fraud'] = ['label' => 'Anti-fraud per pengguna', 'group' => 'Pengawasan', 'kind' => ReportAccess::SALES];
        $out['fraud.events'] = ['label' => 'Rincian void, refund, diskon & selisih kas', 'group' => 'Pengawasan', 'kind' => ReportAccess::SALES];
        $out['tax'] = ['label' => 'Pajak & service charge', 'group' => 'Keuangan', 'kind' => ReportAccess::SALES];
        $out['gross_profit'] = ['label' => 'Laba kotor per outlet', 'group' => 'Keuangan', 'kind' => ReportAccess::SALES];
        foreach (InventoryReport::VIEWS as $view => $label) {
            $out['inventory.'.$view] = ['label' => 'Inventory — '.mb_strtolower($label), 'group' => 'Inventory', 'kind' => ReportAccess::INVENTORY];
        }

        return $out;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function kind(string $key): string
    {
        return self::all()[$key]['kind'] ?? throw new InvalidArgumentException("Laporan tidak dikenal: {$key}");
    }

    public static function label(string $key): string
    {
        return self::all()[$key]['label'] ?? $key;
    }

    /** @return array<string, array<string, string>> grup => [kunci => label] untuk pilihan di form */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::all() as $key => $meta) {
            $out[$meta['group']][$key] = $meta['label'];
        }

        return $out;
    }

    public function build(string $key, ReportFilter $filter): ReportTable
    {
        if (! self::exists($key)) {
            throw new InvalidArgumentException("Laporan tidak dikenal: {$key}");
        }
        [$base, $variant] = array_pad(explode('.', $key, 2), 2, null);

        return match ($base) {
            'sales' => $this->app->make(SalesReport::class)->table($filter, (string) $variant),
            'menu_engineering' => $this->app->make(MenuEngineeringReport::class)->table($filter),
            'fraud' => $variant === 'events' ? $this->fraudEvents($filter) : $this->app->make(FraudReport::class)->table($filter),
            'tax' => $this->app->make(TaxReport::class)->table($filter),
            'gross_profit' => $this->app->make(GrossProfitReport::class)->table($filter),
            'inventory' => $this->app->make(InventoryReport::class)->table($filter, (string) $variant),
            default => throw new InvalidArgumentException("Laporan tidak dikenal: {$key}"),
        };
    }

    private function fraudEvents(ReportFilter $filter): ReportTable
    {
        $events = $this->app->make(FraudReport::class)->events($filter);
        $tz = ReportAccess::timezone();

        return new ReportTable(
            key: 'fraud.events',
            title: 'Rincian Void, Refund, Diskon Manual & Selisih Kas',
            columns: [
                'at' => ['label' => 'Waktu', 'type' => ReportTable::TEXT],
                'type_label' => ['label' => 'Kejadian', 'type' => ReportTable::TEXT],
                'outlet' => ['label' => 'Outlet', 'type' => ReportTable::TEXT],
                'reference' => ['label' => 'Struk', 'type' => ReportTable::TEXT],
                'user' => ['label' => 'Pengguna', 'type' => ReportTable::TEXT],
                'authorized_by' => ['label' => 'Otorisasi', 'type' => ReportTable::TEXT],
                'amount' => ['label' => 'Nominal', 'type' => ReportTable::MONEY],
                'reason' => ['label' => 'Alasan', 'type' => ReportTable::TEXT],
            ],
            rows: array_map(fn (array $e) => [
                'at' => $e['at'] !== null ? CarbonImmutable::parse($e['at'])->setTimezone($tz)->format('d/m/Y H.i') : null,
                'type_label' => $e['type_label'],
                'outlet' => $e['outlet'],
                'reference' => $e['reference'],
                'user' => $e['user'],
                'authorized_by' => $e['authorized_by'],
                'amount' => $e['amount'],
                'reason' => $e['reason'],
            ], $events),
            filters: ['Periode' => $filter->periodLabel()] + $filter->labels,
            summary: [['label' => 'Kejadian', 'value' => (string) count($events), 'type' => ReportTable::NUMBER]],
            notes: ['Maksimal 500 kejadian terbaru. Otorisasi kosong berarti dilakukan sendiri oleh pengguna yang berwenang.'],
        );
    }
}
