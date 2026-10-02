<?php

namespace App\Filament\Pages\Treasury;

use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Treasury\Application\ReceivableService;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;

/**
 * Umur piutang usaha (AR-02).
 *
 * Hanya memuat tagihan keluar. Piutang settlement kartu & QRIS punya sifat yang sama sekali berbeda —
 * ia cair sendiri dalam hitungan hari dan tidak pernah perlu ditagih — jadi mencampurnya ke sini
 * hanya akan membuat angka "piutang yang perlu ditagih" selalu terlihat jauh lebih besar daripada
 * kenyataannya. Laporannya sudah ada tersendiri di Akuntansi → Pencairan Settlement.
 */
class ReceivableAgingPage extends TreasuryReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Umur Piutang';

    protected static ?string $title = 'Umur Piutang Usaha';

    protected static ?string $slug = 'piutang/umur-piutang';

    protected static ?int $navigationSort = 9;

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('to')->label('Posisi per tanggal')->live()
                ->afterStateUpdated(fn () => $this->refreshTable()),
        ])->columns(2)->statePath('');
    }

    protected function build(CarbonImmutable $from, CarbonImmutable $to): ?ReportTable
    {
        return app(ReceivableService::class)->aging($to);
    }
}
