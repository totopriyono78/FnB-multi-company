<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockMovementResource\Pages;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\StockMovement;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Kartu stok: mutasi masuk/keluar dan saldo per bahan per lokasi (FR-INV-09). */
class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $modelLabel = 'mutasi stok';

    protected static ?string $pluralModelLabel = 'Kartu Stok';

    protected static ?string $slug = 'kartu-stok';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['ingredient:id,name,base_unit', 'location.outlet:id,name', 'author:id,name']))
            ->columns([
                TextColumn::make('occurred_at')->label('Waktu')->dateTime('d M Y H.i', $tz)->sortable(),
                TextColumn::make('ingredient.name')->label('Bahan'),
                TextColumn::make('location_id')->label('Lokasi')->formatStateUsing(fn (StockMovement $r) => $r->location->name)
                    ->description(fn (StockMovement $r) => $r->location->outlet->name)->toggleable(),
                TextColumn::make('type')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state) => InventoryFields::movementType($state))
                    ->color(fn (string $state) => InventoryFields::movementColor($state)),
                TextColumn::make('qty')->label('Jumlah')->alignEnd()
                    ->formatStateUsing(fn ($state, StockMovement $r) => ((float) $state > 0 ? '+' : '').InventoryFields::qtyWithUnit((string) $state, $r->ingredient->base_unit))
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success'),
                TextColumn::make('balance_after')->label('Saldo')->alignEnd()
                    ->formatStateUsing(fn ($state, StockMovement $r) => InventoryFields::qtyWithUnit((string) $state, $r->ingredient->base_unit)),
                TextColumn::make('value')->label('Nilai')->alignEnd()->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('reference_no')->label('Dokumen')->placeholder('-')->fontFamily('mono')->searchable(),
                TextColumn::make('reason')->label('Keterangan')->placeholder('-')->limit(32)->tooltip(fn (StockMovement $r) => $r->reason)
                    ->description(fn (StockMovement $r) => in_array('negative_stock', $r->flags, true) ? 'Saldo menjadi minus' : null),
                TextColumn::make('author.name')->label('Oleh')->placeholder('Sistem')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('location_id')->label('Lokasi')->options(fn () => InventoryFields::visibleLocations()),
                SelectFilter::make('ingredient_id')->label('Bahan')->searchable()
                    ->options(fn () => Ingredient::withTrashed()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('type')->label('Jenis')->options(StockMovement::TYPES)->multiple(),
                Filter::make('period')->label('Periode')
                    ->form([
                        DatePicker::make('from')->label('Dari tanggal')->default(now()->subDays(30)),
                        DatePicker::make('until')->label('Sampai tanggal'),
                    ])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn ($w, $v) => $w->where('business_date', '>=', $v))
                        ->when($data['until'] ?? null, fn ($w, $v) => $w->where('business_date', '<=', $v)))
                    ->indicateUsing(fn (array $data) => ($data['from'] ?? null) ? 'Sejak '.CarbonImmutable::parse($data['from'])->format('d M Y') : null),
            ])
            ->emptyStateHeading('Belum ada mutasi')
            ->emptyStateDescription('Mutasi tercatat otomatis dari penjualan, penerimaan barang, transfer, waste, dan opname.');
    }

    /** @return Builder<StockMovement> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('outlet_id', InventoryFields::outletIds());
    }

    public static function canViewAny(): bool
    {
        return InventoryFields::canView();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListStockMovements::route('/')];
    }
}
