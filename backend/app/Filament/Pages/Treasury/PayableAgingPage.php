<?php

namespace App\Filament\Pages\Treasury;

use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Treasury\Application\PayableService;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;

/**
 * Umur hutang usaha (AP-04).
 *
 * Satu angka di laporan ini yang paling layak dipercaya dan paling layak diperiksa: jumlahnya harus
 * sama persis dengan saldo akun Utang Usaha di Neraca pada tanggal yang sama. Selisih berarti ada
 * jurnal yang menyentuh Utang Usaha tanpa lewat faktur pembelian — dan itu selalu perlu dijelaskan.
 * Catatan itu ditulis di kaki laporan, bukan disimpan untuk yang tahu saja.
 */
class PayableAgingPage extends TreasuryReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Umur Hutang';

    protected static ?string $title = 'Umur Hutang Usaha';

    protected static ?string $slug = 'hutang/umur-hutang';

    protected static ?int $navigationSort = 6;

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('to')->label('Posisi per tanggal')->live()
                ->afterStateUpdated(fn () => $this->refreshTable()),
        ])->columns(2)->statePath('');
    }

    protected function build(CarbonImmutable $from, CarbonImmutable $to): ?ReportTable
    {
        return app(PayableService::class)->aging($to);
    }
}
