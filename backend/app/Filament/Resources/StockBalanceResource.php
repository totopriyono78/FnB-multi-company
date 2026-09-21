<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockBalanceResource\Pages;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Domain\Models\StockLocation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Posisi stok per lokasi & peringatan stok minimum (FR-INV-08, FR-RPT-06). */
class StockBalanceResource extends Resource
{
    protected static ?string $model = StockBalance::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $modelLabel = 'stok';

    protected static ?string $pluralModelLabel = 'Posisi Stok';

    protected static ?string $slug = 'stok';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['ingredient:id,code,name,category,base_unit,min_stock', 'location.outlet:id,name,code'])
                ->join('ingredients', 'ingredients.id', '=', 'stock_balances.ingredient_id')
                ->select('stock_balances.*'))
            ->columns([
                TextColumn::make('ingredient.name')->label('Bahan')->searchable(['ingredients.name', 'ingredients.code'])
                    ->description(fn (StockBalance $r) => $r->ingredient->category),
                TextColumn::make('location_id')->label('Lokasi')
                    ->formatStateUsing(fn (StockBalance $r) => $r->location->label()),
                TextColumn::make('qty')->label('Saldo')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state, StockBalance $r) => InventoryFields::qtyWithUnit((string) $state, $r->ingredient->base_unit))
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : null),
                TextColumn::make('min_qty')->label('Minimum')->alignEnd()
                    ->state(fn (StockBalance $r) => $r->min_qty ?? $r->ingredient->min_stock)
                    ->formatStateUsing(fn ($state, StockBalance $r) => (float) $state > 0 ? InventoryFields::qtyWithUnit((string) $state, $r->ingredient->base_unit) : '-'),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (StockBalance $r) => self::status($r))
                    ->formatStateUsing(fn (string $state) => ['negative' => 'Minus', 'low' => 'Di bawah minimum', 'ok' => 'Aman'][$state])
                    ->color(fn (string $state) => ['negative' => 'danger', 'low' => 'warning', 'ok' => 'success'][$state]),
                TextColumn::make('avg_cost')->label('HPP rata-rata')->alignEnd()
                    ->formatStateUsing(fn ($state, StockBalance $r) => MenuFields::rupiah((string) BigDecimal::of((string) $state)->toScale(2, RoundingMode::HALF_UP)).' / '.$r->ingredient->base_unit),
                TextColumn::make('value')->label('Nilai stok')->alignEnd()
                    ->state(fn (StockBalance $r) => (string) BigDecimal::of((string) $r->qty)->multipliedBy((string) $r->avg_cost)->toScale(2, RoundingMode::HALF_UP))
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('last_movement_at')->label('Mutasi terakhir')->since()->placeholder('-'),
            ])
            ->defaultSort('ingredients.name')
            ->filters([
                SelectFilter::make('outlet')->label('Outlet')->options(fn () => InventoryFields::outletOptions())
                    ->query(fn (Builder $q, array $data) => $data['value'] ? $q->whereIn('stock_balances.location_id', StockLocation::query()->where('outlet_id', $data['value'])->select('id')) : $q),
                SelectFilter::make('location_id')->label('Lokasi')->options(fn () => InventoryFields::visibleLocations()),
                Filter::make('low')->label('Hanya stok kritis')->toggle()
                    ->query(fn (Builder $q) => $q->whereRaw('stock_balances.qty < 0 OR (COALESCE(stock_balances.min_qty, ingredients.min_stock) > 0 AND stock_balances.qty < COALESCE(stock_balances.min_qty, ingredients.min_stock))')),
            ])
            ->actions([
                Action::make('card')->label('Kartu stok')->icon('heroicon-o-queue-list')
                    ->url(fn (StockBalance $r) => StockMovementResource::getUrl('index', ['tableFilters' => [
                        'location_id' => ['value' => $r->location_id],
                        'ingredient_id' => ['value' => $r->ingredient_id],
                    ]])),
            ])
            ->emptyStateHeading('Belum ada stok tercatat')
            ->emptyStateDescription('Catat saldo awal lewat Penyesuaian Stok atau terima barang dari pemasok.');
    }

    public static function status(StockBalance $r): string
    {
        $qty = BigDecimal::of((string) $r->qty);
        $min = BigDecimal::of((string) ($r->min_qty ?? $r->ingredient->min_stock));

        return match (true) {
            $qty->isNegative() => 'negative',
            $min->isPositive() && $qty->isLessThan($min) => 'low',
            default => 'ok',
        };
    }

    /** @return Builder<StockBalance> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('stock_balances.location_id', StockLocation::query()->whereIn('outlet_id', InventoryFields::outletIds())->select('id'));
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
        return ['index' => Pages\ListStockBalances::route('/')];
    }
}
