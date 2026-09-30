<?php

namespace App\Filament\Pages\Accounting;

use App\Modules\Accounting\Application\FinancialStatements;
use App\Modules\Reporting\Application\ReportTable;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;

/**
 * Neraca (FIN-02).
 *
 * Neraca sejatinya laporan satu tanggal, bukan rentang. Dua tanggal di sini dipakai sebagaimana
 * neraca dibaca di dunia nyata: kolom utama per tanggal akhir, kolom pembanding per tanggal awal —
 * karena yang ingin diketahui pembaca bukan cuma posisinya hari ini, melainkan ke mana ia bergerak.
 */
class BalanceSheetPage extends AccountingReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'Neraca';

    protected static ?string $title = 'Neraca';

    protected static ?string $slug = 'pembukuan/neraca';

    protected static ?int $navigationSort = 7;

    public function mount(): void
    {
        /*
         * Pembanding bawaannya posisi SEBELUM bulan berjalan, bukan hari pertamanya: saldo per
         * 1 Okt sudah memuat mutasi 1 Okt, sehingga membandingkannya dengan 31 Okt menyembunyikan
         * satu hari. Tanggalnya diisi di sini, bukan dikurangi diam-diam saat laporan disusun —
         * supaya yang tertulis di pemilih tanggal sama dengan yang tertulis di kepala kolom.
         */
        $now = CarbonImmutable::now(config('app.display_timezone'));
        $this->to ??= $now->endOfMonth()->format('Y-m-d');
        $this->from ??= $now->startOfMonth()->subDay()->format('Y-m-d');

        parent::mount();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('to')->label('Per tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
            DatePicker::make('from')->label('Dibandingkan dengan posisi per')->live()->afterStateUpdated(fn () => $this->refreshTable()),
        ])->columns(2)->statePath('');
    }

    protected function build(CarbonImmutable $from, CarbonImmutable $to): ?ReportTable
    {
        return app(FinancialStatements::class)->balanceSheet($from, $to);
    }
}
