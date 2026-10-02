<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Accounting\Application\CashFlowStatement;
use App\Modules\Accounting\Application\FinancialStatements;
use App\Modules\Accounting\Application\GeneralLedger;
use App\Modules\Consolidation\Application\ConsolidationReports;
use App\Modules\Consolidation\Application\HoldingDashboard;
use App\Modules\Treasury\Application\CashAccountService;
use App\Modules\Treasury\Application\CompletenessBoard;
use App\Modules\Treasury\Application\PayableService;
use App\Modules\Treasury\Application\ReceivableService;
use App\Modules\Treasury\Application\VatRecapReport;
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

    /**
     * Laporan akuntansi & kas yang dapat dijadwalkan (FIN-08).
     *
     * Urutannya disengaja: ini pula urutan yang masuk akal dibaca dalam satu paket laporan keuangan —
     * laba rugi, neraca, arus kas, perubahan ekuitas, lalu lampiran pendukungnya.
     */
    public const ACCOUNTING_REPORTS = [
        'accounting.income_statement' => 'Laba Rugi',
        'accounting.balance_sheet' => 'Neraca',
        'accounting.cash_flow' => 'Arus Kas',
        'accounting.equity_changes' => 'Perubahan Ekuitas',
        'accounting.trial_balance' => 'Neraca Saldo',
        'accounting.payable_aging' => 'Umur Hutang Usaha',
        'accounting.receivable_aging' => 'Umur Piutang Usaha',
        'accounting.cash_position' => 'Posisi Kas & Bank',
        'accounting.vat_recap' => 'Rekap PPN Masukan & Keluaran',
        'accounting.completeness' => 'Papan Kelengkapan Entry',
    ];

    /**
     * Laporan grup (CON-06, CON-07, CON-09).
     *
     * Terdaftar di sini supaya ia mendapat mesin ekspor Excel/PDF dan jadwal email yang sama dengan
     * laporan lain. Di entitas yang bukan holding, semua laporan ini kosong — bukan karena disaring,
     * tetapi karena tidak ada satu pun proses konsolidasi yang terlihat dari sana.
     */
    public const CONSOLIDATION_REPORTS = [
        'consolidation.worksheet' => 'Kertas Kerja Konsolidasi',
        'consolidation.balance_sheet' => 'Neraca Konsolidasi',
        'consolidation.income_statement' => 'Laba Rugi Konsolidasi',
        'consolidation.dashboard' => 'Dasbor Holding — Kesiapan Tutup Buku',
    ];

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

        /*
         * Laporan akuntansi & kas (FIN-08). Didaftarkan di sini supaya ia mendapat seluruh mesin
         * Tahap 5 secara cuma-cuma: ekspor Excel/PDF, API, dan yang terpenting — penjadwalan email.
         * Itulah syarat janji "paket laporan keuangan tiap dua hari" bisa ditepati tanpa ada orang
         * yang harus ingat mengirimnya.
         */
        foreach (self::ACCOUNTING_REPORTS as $key => $label) {
            $out[$key] = ['label' => $label, 'group' => 'Akuntansi', 'kind' => ReportAccess::ACCOUNTING];
        }
        foreach (self::CONSOLIDATION_REPORTS as $key => $label) {
            $out[$key] = ['label' => $label, 'group' => 'Holding & Konsolidasi', 'kind' => ReportAccess::CONSOLIDATION];
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
            'accounting' => $this->accounting((string) $variant, $filter),
            'consolidation' => $this->consolidation((string) $variant, $filter),
            default => throw new InvalidArgumentException("Laporan tidak dikenal: {$key}"),
        };
    }

    /**
     * Susun satu laporan akuntansi/kas dari filter laporan yang sama dengan laporan lain.
     *
     * Laporan posisi (neraca, umur hutang/piutang, posisi kas) hanya memakai tanggal AKHIR filter:
     * ia potret, bukan rentang. Pembanding neraca memakai awal periode, sehingga "1–31 Oktober"
     * menghasilkan neraca per 31 Oktober dengan kolom pembanding per 30 September — persis yang
     * dibutuhkan paket laporan bulanan.
     */
    private function accounting(string $variant, ReportFilter $filter): ReportTable
    {
        $from = $filter->from;
        $to = $filter->to;
        $outletId = $filter->outletId;

        return match ($variant) {
            'income_statement' => $this->app->make(FinancialStatements::class)
                ->incomeStatement($from, $to, $outletId, $filter->brandId),
            'balance_sheet' => $this->app->make(FinancialStatements::class)
                ->balanceSheet($from->subDay(), $to),
            'cash_flow' => $this->app->make(CashFlowStatement::class)->build($from, $to, $outletId),
            'equity_changes' => $this->app->make(FinancialStatements::class)->equityChanges($from, $to),
            'trial_balance' => $this->app->make(GeneralLedger::class)->trialBalance($from, $to),
            'payable_aging' => $this->app->make(PayableService::class)->aging($to),
            'receivable_aging' => $this->app->make(ReceivableService::class)->aging($to),
            'cash_position' => $this->app->make(CashAccountService::class)->positions($to),
            'vat_recap' => $this->app->make(VatRecapReport::class)->build($from, $to),
            'completeness' => $this->app->make(CompletenessBoard::class)->build($from, $to),
            default => throw new InvalidArgumentException("Laporan akuntansi tidak dikenal: {$variant}"),
        };
    }

    /**
     * Laporan konsolidasi dari filter rentang tanggal yang sama dengan laporan lain.
     *
     * Jembatannya satu: rentang tanggal filter diterjemahkan menjadi satu proses konsolidasi.
     * Angkanya tetap datang dari snapshot proses itu, bukan dibaca ulang dari buku besar — jadi
     * laporan terjadwal tidak pernah menampilkan angka yang belum pernah ditarik dan diperiksa.
     */
    private function consolidation(string $variant, ReportFilter $filter): ReportTable
    {
        $reports = $this->app->make(ConsolidationReports::class);
        $run = $reports->runForPeriod($filter->from, $filter->to);
        $label = self::CONSOLIDATION_REPORTS['consolidation.'.$variant] ?? 'Laporan Konsolidasi';

        if ($run === null) {
            return $reports->missing($label, $filter->from, $filter->to);
        }

        return match ($variant) {
            'worksheet' => $reports->worksheet($run),
            'balance_sheet' => $reports->balanceSheet($run),
            'income_statement' => $reports->incomeStatement($run),
            'dashboard' => $this->app->make(HoldingDashboard::class)->build($run),
            default => throw new InvalidArgumentException("Laporan konsolidasi tidak dikenal: {$variant}"),
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
