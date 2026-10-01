<?php

namespace App\Filament\Pages\Accounting;

use App\Modules\Accounting\Application\FinancialStatements;
use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Livewire\Attributes\Url;

/** Laporan Laba Rugi (FIN-01). */
class IncomeStatementPage extends AccountingReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Laba Rugi';

    protected static ?string $title = 'Laporan Laba Rugi';

    protected static ?string $slug = 'pembukuan/laba-rugi';

    protected static ?int $navigationSort = 6;

    #[Url(as: 'outlet')]
    public ?string $outletId = null;

    #[Url(as: 'brand')]
    public ?string $brandId = null;

    /** @return array<string, mixed> */
    protected function formState(): array
    {
        return parent::formState() + ['outletId' => $this->outletId, 'brandId' => $this->brandId];
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
            DatePicker::make('to')->label('Sampai tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
            /*
             * Laba rugi per outlet/brand (FIN-02, ACC-03). Dibaca dari dimensi di baris jurnal, jadi
             * tidak ada bagan akun terpisah per outlet yang harus dirawat.
             */
            Select::make('outletId')->label('Outlet')->placeholder('Semua outlet')->live()->searchable()
                ->options(fn () => Outlet::query()->orderBy('name')->pluck('name', 'id')->all())
                ->afterStateUpdated(fn () => $this->refreshTable()),
            Select::make('brandId')->label('Brand')->placeholder('Semua brand')->live()->searchable()
                ->options(fn () => Brand::query()->orderBy('name')->pluck('name', 'id')->all())
                ->afterStateUpdated(fn () => $this->refreshTable()),
        ])->columns(4)->statePath('');
    }

    protected function build(CarbonImmutable $from, CarbonImmutable $to): ?ReportTable
    {
        return app(FinancialStatements::class)->incomeStatement($from, $to, $this->outletId ?: null, $this->brandId ?: null);
    }
}
