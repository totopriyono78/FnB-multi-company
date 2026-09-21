<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockLocationResource\Pages;
use App\Filament\Support\InventoryFields;
use App\Modules\Catalog\Domain\Models\KitchenStation;
use App\Modules\Inventory\Domain\Models\StockLocation;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/** Gudang / lokasi stok per outlet (FR-INV-02). */
class StockLocationResource extends Resource
{
    protected static ?string $model = StockLocation::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $modelLabel = 'lokasi stok';

    protected static ?string $pluralModelLabel = 'Lokasi Stok';

    protected static ?string $slug = 'lokasi-stok';

    protected static ?int $navigationSort = 9;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->columns(3)->schema([
                Select::make('outlet_id')->label('Outlet')->required()->disabledOn('edit')
                    ->options(fn () => InventoryFields::manageableOutlets()),
                TextInput::make('code')->label('Kode')->required()->maxLength(20)->regex('/^[A-Za-z0-9_-]+$/')->placeholder('BAR')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, callable $get) => $rule->where('outlet_id', $get('outlet_id'))),
                TextInput::make('name')->label('Nama lokasi')->required()->maxLength(60)->placeholder('Bar Kopi'),
                Select::make('kitchen_station_id')->label('Stasiun dapur')
                    ->options(fn () => KitchenStation::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->placeholder('Tidak dipetakan')
                    ->helperText('Penjualan menu dari stasiun ini memotong stok lokasi ini. Tanpa pemetaan, stok dipotong dari lokasi utama.'),
                Toggle::make('is_default')->label('Lokasi utama')->inline(false)
                    ->helperText('Tujuan bawaan penerimaan barang & pemotongan penjualan.'),
                Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('outlet:id,name,code'))
            ->columns([
                TextColumn::make('outlet.name')->label('Outlet')->sortable(),
                TextColumn::make('code')->label('Kode')->fontFamily('mono'),
                TextColumn::make('name')->label('Nama'),
                TextColumn::make('kitchen_station_id')->label('Stasiun dapur')->placeholder('-')
                    ->formatStateUsing(fn (?string $state) => $state === null ? null : KitchenStation::query()->whereKey($state)->value('name')),
                TextColumn::make('is_default')->label('Utama')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Utama' : 'Tambahan')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
                TextColumn::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->defaultSort('outlet_id')
            ->filters([SelectFilter::make('outlet_id')->label('Outlet')->options(fn () => InventoryFields::outletOptions())])
            ->actions([EditAction::make()->label('Ubah')])
            ->emptyStateHeading('Belum ada lokasi stok')
            ->emptyStateDescription('Setiap outlet otomatis memiliki Gudang Utama. Tambahkan lokasi seperti Bar atau Dapur bila stoknya dipisah.');
    }

    /** @return Builder<StockLocation> */
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
        return InventoryFields::manageableOutlets() !== [];
    }

    public static function canEdit(Model $record): bool
    {
        $user = InventoryFields::user();

        return $record instanceof StockLocation && $user !== null && InventoryFields::access()->canManageOutlet($user, $record->outlet);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockLocations::route('/'),
            'create' => Pages\CreateStockLocation::route('/create'),
            'edit' => Pages\EditStockLocation::route('/{record}/edit'),
        ];
    }
}
