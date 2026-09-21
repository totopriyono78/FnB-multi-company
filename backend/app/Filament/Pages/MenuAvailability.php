<?php

namespace App\Filament\Pages;

use App\Filament\Support\MenuFields;
use App\Filament\Tables\Columns\LabeledToggleColumn;
use App\Modules\Catalog\Application\AvailabilityService;
use App\Modules\Catalog\Application\MenuScope;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\OutletItemAvailability;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Tenancy\Application\WritableCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;

/**
 * Ketersediaan menu per outlet: tandai habis & sembunyikan menu (FR-MENU-08).
 * Menu habis otomatis kembali tersedia saat tutup hari (Tahap 3).
 *
 * @property-read Form $form
 */
class MenuAvailability extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $navigationGroup = 'Menu & Harga';

    protected static ?string $navigationLabel = 'Ketersediaan Menu';

    protected static ?string $title = 'Ketersediaan Menu';

    protected static ?string $slug = 'ketersediaan-menu';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.menu-availability';

    #[Url(as: 'outlet')]
    public ?string $outletId = null;

    public static function canAccess(): bool
    {
        $user = MenuFields::user();

        return $user !== null && ($user->can('menu.sold_out') || $user->can('menu.manage')) && MenuFields::outlets() !== [];
    }

    public function mount(): void
    {
        $outlets = MenuFields::outlets();
        if ($this->outletId === null || ! array_key_exists($this->outletId, $outlets)) {
            $this->outletId = array_key_first($outlets);
        }
        $this->form->fill(['outletId' => $this->outletId]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('outletId')->label('Outlet')
                ->options(fn () => MenuFields::outlets())
                ->required()->live()
                ->afterStateUpdated(function (?string $state): void {
                    $this->outletId = $state;
                    $this->resetTable();
                }),
        ]);
    }

    public function outlet(): ?Outlet
    {
        if ($this->outletId === null || ! array_key_exists($this->outletId, MenuFields::outlets())) {
            return null;
        }

        return Outlet::query()->find($this->outletId);
    }

    private function canList(): bool
    {
        $user = MenuFields::user();
        $outlet = $this->outlet();

        return $user !== null && $outlet !== null && WritableCompany::allows()
            && (app(MenuScope::class)->canManageBrand($user, $outlet->brand_id) || $user->can('outlet.manage'));
    }

    private function canSoldOut(): bool
    {
        return WritableCompany::allows() && (MenuFields::user()?->can('menu.sold_out') ?? false) && $this->outlet() !== null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $outlet = $this->outlet();
                if ($outlet === null) {
                    return Item::query()->whereRaw('1 = 0');
                }

                return Item::query()
                    ->with('category')
                    ->where('brand_id', $outlet->brand_id)
                    ->where('is_active', true)
                    ->addSelect([
                        'avail_listed' => OutletItemAvailability::query()->select('is_listed')
                            ->whereColumn('item_id', 'items.id')->where('outlet_id', $outlet->id)->limit(1),
                        'avail_sold_out' => OutletItemAvailability::query()->select('is_sold_out')
                            ->whereColumn('item_id', 'items.id')->where('outlet_id', $outlet->id)->limit(1),
                    ]);
            })
            ->columns([
                TextColumn::make('name')->label('Menu')->searchable()->sortable()->description(fn (Item $record) => $record->sku),
                TextColumn::make('category.name')->label('Kategori'),
                LabeledToggleColumn::make('avail_sold_out')->label('Habis')
                    ->switchLabel(fn (Item $record) => 'Tandai habis: '.$record->name)
                    ->getStateUsing(fn (Item $record) => (bool) ($record->getAttributes()['avail_sold_out'] ?? false))
                    ->disabled(fn () => ! $this->canSoldOut())
                    ->onColor('danger')
                    ->updateStateUsing(fn (Item $record, bool $state) => $this->apply($record, null, $state)),
                LabeledToggleColumn::make('avail_listed')->label('Tampil di POS')
                    ->switchLabel(fn (Item $record) => 'Tampil di POS: '.$record->name)
                    ->getStateUsing(fn (Item $record) => (bool) ($record->getAttributes()['avail_listed'] ?? true))
                    ->disabled(fn () => ! $this->canList())
                    ->updateStateUsing(fn (Item $record, bool $state) => $this->apply($record, $state, null)),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('category_id')->label('Kategori')
                    ->options(fn () => MenuCategory::query()->where('brand_id', $this->outlet()?->brand_id)->orderBy('name')->pluck('name', 'id')->all()),
                TernaryFilter::make('sold_out')->label('Status habis')
                    ->trueLabel('Hanya yang habis')->falseLabel('Hanya yang tersedia')
                    ->queries(
                        true: fn (Builder $q) => $q->whereHas('availability', fn (Builder $a) => $a->where('outlet_id', $this->outletId)->where('is_sold_out', true)),
                        false: fn (Builder $q) => $q->whereDoesntHave('availability', fn (Builder $a) => $a->where('outlet_id', $this->outletId)->where('is_sold_out', true)),
                    ),
            ])
            ->bulkActions([
                BulkAction::make('available')->label('Tandai tersedia')->icon('heroicon-m-check')
                    ->visible(fn () => $this->canSoldOut())
                    ->action(fn (Collection $records) => $this->applyMany($records, false))
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('soldOut')->label('Tandai habis')->icon('heroicon-m-no-symbol')->color('danger')
                    ->visible(fn () => $this->canSoldOut())
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => $this->applyMany($records, true))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading('Tidak ada menu')
            ->emptyStateDescription('Pilih outlet atau tambahkan menu untuk brand outlet ini.');
    }

    /** @param  Collection<int, Model>  $records */
    private function applyMany(Collection $records, bool $soldOut): void
    {
        foreach ($records as $record) {
            if ($record instanceof Item) {
                $this->apply($record, null, $soldOut);
            }
        }
    }

    private function apply(Item $item, ?bool $listed, ?bool $soldOut): void
    {
        $outlet = $this->outlet();
        $user = MenuFields::user();
        $allowed = $outlet !== null && $user !== null
            && app(AccessScope::class)->allowsOutlet($user, $outlet)
            && ($listed === null || $this->canList())
            && ($soldOut === null || $this->canSoldOut());
        if (! $allowed) {
            Notification::make()->danger()->title('Anda tidak berwenang mengubah ketersediaan menu ini.')->send();

            return;
        }

        app(AvailabilityService::class)->set($item, $outlet, $listed, $soldOut, $user);
    }
}
