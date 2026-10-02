<?php

namespace App\Filament\Pages\Accounting;

use App\Modules\Accounting\Application\CashFlowStatement;
use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Livewire\Attributes\Url;

/**
 * Laporan Arus Kas (FIN-03), metode langsung.
 *
 * Laporan ketiga yang selalu diminta pemilik, dan satu-satunya yang menjawab "uangnya ke mana"
 * dengan baris-baris yang benar-benar terjadi, bukan penyesuaian dari laba.
 */
class CashFlowPage extends AccountingReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $navigationLabel = 'Arus Kas';

    protected static ?string $title = 'Laporan Arus Kas';

    protected static ?string $slug = 'pembukuan/arus-kas';

    protected static ?int $navigationSort = 8;

    #[Url(as: 'outlet')]
    public ?string $outletId = null;

    /** @return array<string, mixed> */
    protected function formState(): array
    {
        return parent::formState() + ['outletId' => $this->outletId];
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
            DatePicker::make('to')->label('Sampai tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
            Select::make('outletId')->label('Outlet')->placeholder('Semua outlet')->live()->searchable()
                ->options(fn () => Outlet::query()->orderBy('name')->pluck('name', 'id')->all())
                ->afterStateUpdated(fn () => $this->refreshTable()),
        ])->columns(3)->statePath('');
    }

    protected function build(CarbonImmutable $from, CarbonImmutable $to): ?ReportTable
    {
        return app(CashFlowStatement::class)->build($from, $to, $this->outletId ?: null);
    }
}
