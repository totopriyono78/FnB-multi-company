<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockTransferResource\Pages;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Inventory\Domain\Models\StockTransfer;
use App\Modules\Inventory\Domain\Models\StockTransferLine;
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

/** Transfer stok antar gudang/outlet dengan status kirim–terima (FR-INV-05). */
class StockTransferResource extends Resource
{
    protected static ?string $model = StockTransfer::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $modelLabel = 'transfer stok';

    protected static ?string $pluralModelLabel = 'Transfer Stok';

    protected static ?string $slug = 'transfer-stok';

    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->columns(2)->schema([
                Select::make('from_location_id')->label('Kirim dari')->options(fn () => InventoryFields::manageableLocations())->required()->live(),
                Select::make('to_location_id')->label('Kirim ke')->required()->different('from_location_id')
                    ->options(fn (Get $get) => array_diff_key(InventoryFields::allLocations(), [(string) $get('from_location_id') => true])),
                Textarea::make('notes')->label('Catatan')->rows(1)->maxLength(300)->columnSpanFull(),
            ]),
            Section::make('Bahan dikirim')->schema([
                Repeater::make('lines')->label('Daftar bahan')->hiddenLabel()
                    ->schema([
                        Grid::make(12)->schema([
                            Select::make('ingredient_id')->label('Bahan')->options(fn () => InventoryFields::ingredientOptions(true))
                                ->searchable()->required()->live()->columnSpan(6)
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                            InventoryFields::qty('qty', 'Jumlah')->columnSpan(3)->rules(['gt:0'])
                                ->suffix(fn (Get $get) => InventoryFields::baseUnit($get('ingredient_id'))),
                            TextInput::make('note')->label('Catatan')->maxLength(200)->columnSpan(3),
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['fromLocation.outlet:id,name', 'toLocation.outlet:id,name'])->withCount('lines'))
            ->columns([
                TextColumn::make('sent_at')->label('Dikirim')->dateTime('d M Y H.i', $tz)->sortable(),
                TextColumn::make('number')->label('Nomor')->fontFamily('mono')->searchable(),
                TextColumn::make('from_location_id')->label('Dari')->formatStateUsing(fn (StockTransfer $r) => $r->fromLocation->label()),
                TextColumn::make('to_location_id')->label('Ke')->formatStateUsing(fn (StockTransfer $r) => $r->toLocation->label()),
                TextColumn::make('lines_count')->label('Bahan')->alignEnd(),
                TextColumn::make('total_value')->label('Nilai')->alignEnd()->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => StockTransfer::STATUSES[$state])
                    ->color(fn (string $state) => InventoryFields::TRANSFER_COLORS[$state]),
                TextColumn::make('received_at')->label('Diterima')->dateTime('d M Y H.i', $tz)->placeholder('-'),
            ])
            ->defaultSort('sent_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Status')->options(StockTransfer::STATUSES),
                SelectFilter::make('to_outlet_id')->label('Outlet tujuan')->options(fn () => InventoryFields::outletOptions()),
                SelectFilter::make('from_outlet_id')->label('Outlet asal')->options(fn () => InventoryFields::outletOptions()),
            ])
            ->actions([ViewAction::make()->label('Detail')])
            ->emptyStateHeading('Belum ada transfer stok')
            ->emptyStateDescription('Kirim bahan antar gudang atau antar outlet; stok bertambah di tujuan setelah diterima.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $tz = (string) config('app.display_timezone');

        return $infolist->schema([
            InfoSection::make()->columns(4)->schema([
                TextEntry::make('number')->label('Nomor'),
                TextEntry::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => StockTransfer::STATUSES[$state])
                    ->color(fn (string $state) => InventoryFields::TRANSFER_COLORS[$state]),
                TextEntry::make('from_location_id')->label('Dari')->formatStateUsing(fn (StockTransfer $record) => $record->fromLocation->label()),
                TextEntry::make('to_location_id')->label('Ke')->formatStateUsing(fn (StockTransfer $record) => $record->toLocation->label()),
                TextEntry::make('sent_at')->label('Dikirim')->dateTime('d M Y H.i', $tz),
                TextEntry::make('received_at')->label('Diterima')->dateTime('d M Y H.i', $tz)->placeholder('-'),
                TextEntry::make('total_value')->label('Nilai kirim')->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextEntry::make('notes')->label('Catatan')->placeholder('-'),
                TextEntry::make('receive_note')->label('Catatan penerimaan')->placeholder('-')->visible(fn (StockTransfer $record) => $record->receive_note !== null),
                TextEntry::make('cancel_reason')->label('Alasan batal')->visible(fn (StockTransfer $record) => $record->cancel_reason !== null),
            ]),
            RepeatableEntry::make('lines')->label('Bahan')->columns(4)->schema([
                TextEntry::make('ingredient.name')->label('Bahan'),
                TextEntry::make('qty_sent')->label('Dikirim')
                    ->formatStateUsing(fn ($state, StockTransferLine $record) => InventoryFields::qtyWithUnit((string) $state, $record->ingredient->base_unit)),
                TextEntry::make('qty_received')->label('Diterima')->placeholder('-')
                    ->formatStateUsing(fn ($state, StockTransferLine $record) => InventoryFields::qtyWithUnit((string) $state, $record->ingredient->base_unit))
                    ->color(fn ($state, StockTransferLine $record) => $state !== null && (float) $state !== (float) $record->qty_sent ? 'danger' : null),
                TextEntry::make('note')->label('Catatan')->placeholder('-'),
            ])->columnSpanFull(),
        ]);
    }

    /** @return Builder<StockTransfer> */
    public static function getEloquentQuery(): Builder
    {
        $ids = InventoryFields::outletIds();

        return parent::getEloquentQuery()->where(fn (Builder $q) => $q->whereIn('from_outlet_id', $ids)->orWhereIn('to_outlet_id', $ids));
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
            'index' => Pages\ListStockTransfers::route('/'),
            'create' => Pages\CreateStockTransfer::route('/create'),
            'view' => Pages\ViewStockTransfer::route('/{record}'),
        ];
    }
}
