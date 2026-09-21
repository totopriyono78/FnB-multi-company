<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockAdjustmentResource\Pages;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Inventory\Domain\Models\StockAdjustment;
use App\Modules\Inventory\Domain\Models\StockAdjustmentLine;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Penyesuaian stok & waste (FR-INV-05). Dokumen tidak dapat diubah setelah disimpan. */
class StockAdjustmentResource extends Resource
{
    protected static ?string $model = StockAdjustment::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-vertical';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $modelLabel = 'penyesuaian stok';

    protected static ?string $pluralModelLabel = 'Penyesuaian & Waste';

    protected static ?string $slug = 'penyesuaian-stok';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->columns(4)->schema([
                Select::make('location_id')->label('Lokasi')->options(fn () => InventoryFields::manageableLocations())->required()->columnSpan(2),
                Select::make('type')->label('Jenis')->options(StockAdjustment::TYPES)->default('waste')->required()->live()
                    ->afterStateUpdated(fn (callable $set) => $set('reason_code', null)),
                Select::make('reason_code')->label('Alasan')->required()
                    ->options(fn (Get $get) => $get('type') === 'adjustment' ? StockAdjustment::ADJUSTMENT_REASONS : StockAdjustment::WASTE_REASONS),
                DateTimePicker::make('occurred_at')->label('Waktu kejadian')->seconds(false)
                    ->default(now())
                    ->timezone((string) config('app.display_timezone')),
                Textarea::make('notes')->label('Keterangan')->rows(1)->maxLength(300)->columnSpan(3)
                    ->required(fn (Get $get) => $get('reason_code') === 'other'),
            ]),
            Section::make('Bahan')
                ->description(fn (Get $get) => $get('type') === 'adjustment'
                    ? 'Isi positif untuk menambah stok, negatif untuk mengurangi. Harga pokok hanya untuk penambahan (kosongkan = HPP rata-rata).'
                    : 'Isi jumlah yang dibuang dalam satuan dasar bahan.')
                ->schema([
                    Repeater::make('lines')->label('Daftar bahan')->hiddenLabel()
                        ->schema([
                            Grid::make(12)->schema([
                                Select::make('ingredient_id')->label('Bahan')->options(fn () => InventoryFields::ingredientOptions(true))
                                    ->searchable()->required()->live()->columnSpan(4)
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                InventoryFields::qty('qty', 'Jumlah', true)->columnSpan(2)
                                    ->suffix(fn (Get $get) => InventoryFields::baseUnit($get('ingredient_id')))
                                    ->rules(['not_in:0']),
                                TextInput::make('unit_cost')->label('Harga pokok / satuan')->prefix('Rp')->columnSpan(2)
                                    ->inputMode('decimal')->rules(['nullable', 'decimal:0,6', 'min:0'])
                                    ->visible(fn (Get $get) => $get('../../type') === 'adjustment'),
                                TextInput::make('note')->label('Catatan')->maxLength(200)->columnSpan(4),
                            ]),
                        ])
                        ->addActionLabel('Tambah bahan')
                        ->minItems(1)->maxItems(200)->defaultItems(1),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['location.outlet:id,name', 'author:id,name'])->withCount('lines'))
            ->columns([
                TextColumn::make('occurred_at')->label('Waktu')->dateTime('d M Y H.i', $tz)->sortable(),
                TextColumn::make('number')->label('Nomor')->fontFamily('mono')->searchable(),
                TextColumn::make('type')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state) => StockAdjustment::TYPES[$state])
                    ->color(fn (string $state) => $state === 'waste' ? 'danger' : 'warning'),
                TextColumn::make('reason_code')->label('Alasan')
                    ->formatStateUsing(fn (string $state, StockAdjustment $r) => StockAdjustment::reasonLabel($r->type, $state)),
                TextColumn::make('location_id')->label('Lokasi')->formatStateUsing(fn (StockAdjustment $r) => $r->location->label()),
                TextColumn::make('lines_count')->label('Bahan')->alignEnd(),
                TextColumn::make('total_value')->label('Nilai')->alignEnd()->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('author.name')->label('Dicatat oleh')->placeholder('-'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('outlet_id')->label('Outlet')->options(fn () => InventoryFields::outletOptions()),
                SelectFilter::make('type')->label('Jenis')->options(StockAdjustment::TYPES),
            ])
            ->actions([ViewAction::make()->label('Detail')])
            ->emptyStateHeading('Belum ada penyesuaian atau waste')
            ->emptyStateDescription('Catat bahan yang dibuang (kedaluwarsa, tumpah) atau saldo awal stok di sini.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $tz = (string) config('app.display_timezone');

        return $infolist->schema([
            InfoSection::make()->columns(4)->schema([
                TextEntry::make('number')->label('Nomor'),
                TextEntry::make('type')->label('Jenis')->formatStateUsing(fn (string $state) => StockAdjustment::TYPES[$state]),
                TextEntry::make('reason_code')->label('Alasan')->formatStateUsing(fn (string $state, StockAdjustment $record) => StockAdjustment::reasonLabel($record->type, $state)),
                TextEntry::make('occurred_at')->label('Waktu')->dateTime('d M Y H.i', $tz),
                TextEntry::make('location_id')->label('Lokasi')->formatStateUsing(fn (StockAdjustment $record) => $record->location->label()),
                TextEntry::make('author.name')->label('Dicatat oleh')->placeholder('-'),
                TextEntry::make('total_value')->label('Nilai')->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextEntry::make('notes')->label('Keterangan')->placeholder('-'),
            ]),
            RepeatableEntry::make('lines')->label('Bahan')->columns(5)->schema([
                TextEntry::make('ingredient.name')->label('Bahan'),
                TextEntry::make('qty')->label('Jumlah')
                    ->formatStateUsing(fn ($state, StockAdjustmentLine $record) => InventoryFields::qtyWithUnit((string) $state, $record->ingredient->base_unit)),
                TextEntry::make('unit_cost')->label('Harga pokok')->formatStateUsing(fn ($state) => MenuFields::rupiah(round((float) $state, 2))),
                TextEntry::make('value')->label('Nilai')->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextEntry::make('note')->label('Catatan')->placeholder('-'),
            ])->columnSpanFull(),
        ]);
    }

    /** @return Builder<StockAdjustment> */
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
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockAdjustments::route('/'),
            'create' => Pages\CreateStockAdjustment::route('/create'),
            'view' => Pages\ViewStockAdjustment::route('/{record}'),
        ];
    }
}
