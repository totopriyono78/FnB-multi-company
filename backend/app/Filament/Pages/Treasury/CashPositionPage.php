<?php

namespace App\Filament\Pages\Treasury;

use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Treasury\Application\CashAccountService;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;

/**
 * Posisi kas & bank (CSH-01).
 *
 * Menjawab pertanyaan yang paling sering diajukan pemilik dan paling jarang bisa dijawab cepat:
 * *ada berapa uang kita, dan di mana saja?* Angkanya saldo **buku** — yang tercatat — dan layarnya
 * mengatakan itu apa adanya, karena saldo buku dan saldo rekening di bank hampir selalu berbeda
 * sampai rekonsiliasi dikerjakan.
 */
class CashPositionPage extends TreasuryReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationLabel = 'Posisi Kas & Bank';

    protected static ?string $title = 'Posisi Kas & Bank';

    protected static ?string $slug = 'kas/posisi';

    protected static ?int $navigationSort = 1;

    public function form(Form $form): Form
    {
        return $form->schema([
            // Satu tanggal saja: posisi kas adalah potret, bukan rentang.
            DatePicker::make('to')->label('Saldo per tanggal')->live()
                ->afterStateUpdated(fn () => $this->refreshTable()),
        ])->columns(2)->statePath('');
    }

    protected function build(CarbonImmutable $from, CarbonImmutable $to): ?ReportTable
    {
        return app(CashAccountService::class)->positions($to);
    }
}
