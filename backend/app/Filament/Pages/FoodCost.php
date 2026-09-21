<?php

namespace App\Filament\Pages;

use App\Filament\Support\InventoryFields;
use App\Modules\Inventory\Application\FoodCostReport;
use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Food cost teoritis per menu & aktual per periode (FR-INV-10, FR-RPT-06).
 *
 * @property-read Form $form
 */
class FoodCost extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Food Cost';

    protected static ?string $title = 'Food Cost';

    protected static ?string $slug = 'food-cost';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'filament.pages.food-cost';

    #[Url(as: 'outlet')]
    public ?string $outletId = null;

    #[Url(as: 'dari')]
    public ?string $from = null;

    #[Url(as: 'sampai')]
    public ?string $to = null;

    public static function canAccess(): bool
    {
        return InventoryFields::canView() && InventoryFields::outletIds() !== [];
    }

    public function mount(): void
    {
        $outlets = InventoryFields::outletOptions();
        if ($this->outletId === null || ! array_key_exists($this->outletId, $outlets)) {
            $this->outletId = array_key_first($outlets);
        }
        $this->to = $this->validDate($this->to) ?? InventoryAccess::today();
        $this->from = $this->validDate($this->from) ?? CarbonImmutable::parse($this->to)->startOfMonth()->format('Y-m-d');
        if ($this->from > $this->to) {
            $this->from = $this->to;
        }
        $this->form->fill(['outletId' => $this->outletId, 'from' => $this->from, 'to' => $this->to]);
    }

    public function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Select::make('outletId')->label('Outlet')->options(fn () => InventoryFields::outletOptions())->required()->live()
                ->afterStateUpdated(fn (?string $state) => $this->outletId = $state),
            DatePicker::make('from')->label('Dari tanggal')->required()->live()
                ->afterStateUpdated(fn (?string $state) => $this->from = $this->validDate($state) ?? $this->from),
            DatePicker::make('to')->label('Sampai tanggal')->required()->live()->afterOrEqual('from')
                ->afterStateUpdated(fn (?string $state) => $this->to = $this->validDate($state) ?? $this->to),
        ]);
    }

    public function outlet(): ?Outlet
    {
        if ($this->outletId === null || ! in_array($this->outletId, InventoryFields::outletIds(), true)) {
            return null;
        }

        return Outlet::withTrashed()->find($this->outletId);
    }

    /** @return array<string, mixed>|null */
    public function actual(): ?array
    {
        $outlet = $this->outlet();
        if ($outlet === null || $this->from === null || $this->to === null || $this->from > $this->to) {
            return null;
        }
        if (CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) > 366) {
            return null;
        }

        return app(FoodCostReport::class)->actual([$outlet->id], $this->from, $this->to);
    }

    /** @return list<array<string, mixed>> */
    public function menu(): array
    {
        $outlet = $this->outlet();

        return $outlet === null ? [] : app(FoodCostReport::class)->menu($outlet);
    }

    private function validDate(?string $value): ?string
    {
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }

        return substr($value, 0, 10);
    }
}
