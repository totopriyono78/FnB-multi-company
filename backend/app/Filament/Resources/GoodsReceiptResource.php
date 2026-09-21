<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GoodsReceiptResource\Pages;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Purchasing\Domain\Models\GoodsReceipt;
use App\Modules\Purchasing\Domain\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Domain\Models\PurchaseOrder;
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

/** Penerimaan barang dari PO atau tanpa PO (FR-INV-05). */
class GoodsReceiptResource extends Resource
{
    protected static ?string $model = GoodsReceipt::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'Pembelian';

    protected static ?string $modelLabel = 'penerimaan barang';

    protected static ?string $pluralModelLabel = 'Penerimaan Barang';

    protected static ?string $slug = 'penerimaan-barang';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->description('Untuk pembelian tanpa PO, mis. belanja harian di pasar. Barang dari PO diterima lewat halaman PO.')
                ->columns(3)->schema([
                    Select::make('location_id')->label('Diterima di')->options(fn () => InventoryFields::manageableLocations('receive'))->required(),
                    Select::make('supplier_id')->label('Pemasok')->options(fn () => InventoryFields::supplierOptions())->searchable()
                        ->placeholder('Tanpa pemasok terdaftar'),
                    TextInput::make('supplier_invoice_no')->label('Nomor nota')->maxLength(60),
                    DateTimePicker::make('received_at')->label('Waktu terima')->seconds(false)->default(now())
                        ->timezone((string) config('app.display_timezone')),
                    Textarea::make('notes')->label('Catatan')->rows(1)->maxLength(300)->columnSpan(2),
                ]),
            Section::make('Barang')->schema([
                Repeater::make('lines')->label('Daftar bahan')->hiddenLabel()
                    ->schema([
                        Grid::make(12)->schema([
                            Select::make('ingredient_id')->label('Bahan')->options(fn () => InventoryFields::ingredientOptions(true))
                                ->searchable()->required()->live()->columnSpan(4)
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                ->afterStateUpdated(fn (callable $set, ?string $state) => $set('unit_name', array_key_last(InventoryFields::unitOptions($state)))),
                            Select::make('unit_name')->label('Satuan')->required()->columnSpan(3)
                                ->options(fn (Get $get) => InventoryFields::unitOptions($get('ingredient_id'))),
                            InventoryFields::qty('qty', 'Jumlah')->rules(['gt:0'])->columnSpan(2),
                            MenuFields::money('unit_price', 'Harga / satuan')->columnSpan(3),
                        ]),
                    ])
                    ->addActionLabel('Tambah barang')
                    ->minItems(1)->maxItems(200)->defaultItems(1),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier:id,name', 'purchaseOrder:id,number', 'location.outlet:id,name', 'receiver:id,name']))
            ->columns([
                TextColumn::make('received_at')->label('Diterima')->dateTime('d M Y H.i', $tz)->sortable(),
                TextColumn::make('number')->label('Nomor')->fontFamily('mono')->searchable(),
                TextColumn::make('purchaseOrder.number')->label('PO')->fontFamily('mono')->placeholder('Tanpa PO'),
                TextColumn::make('supplier.name')->label('Pemasok')->placeholder('-'),
                TextColumn::make('location_id')->label('Lokasi')->formatStateUsing(fn (GoodsReceipt $r) => $r->location->label()),
                TextColumn::make('supplier_invoice_no')->label('No. faktur')->placeholder('-'),
                TextColumn::make('total')->label('Total')->alignEnd()->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('receiver.name')->label('Penerima')->placeholder('-')->toggleable(),
            ])
            ->defaultSort('received_at', 'desc')
            ->filters([
                SelectFilter::make('outlet_id')->label('Outlet')->options(fn () => InventoryFields::outletOptions(true)),
                SelectFilter::make('supplier_id')->label('Pemasok')->options(fn () => InventoryFields::supplierOptions()),
                SelectFilter::make('purchase_order_id')->label('PO')->searchable()
                    ->options(fn () => PurchaseOrder::query()->whereIn('outlet_id', InventoryFields::outletIds(true))->latest()->limit(200)->pluck('number', 'id')->all()),
            ])
            ->actions([ViewAction::make()->label('Detail')])
            ->emptyStateHeading('Belum ada penerimaan barang')
            ->emptyStateDescription('Penerimaan menambah stok dan memperbarui HPP rata-rata bahan.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $tz = (string) config('app.display_timezone');

        return $infolist->schema([
            InfoSection::make()->columns(4)->schema([
                TextEntry::make('number')->label('Nomor'),
                TextEntry::make('purchaseOrder.number')->label('PO')->placeholder('Tanpa PO'),
                TextEntry::make('supplier.name')->label('Pemasok')->placeholder('-'),
                TextEntry::make('location_id')->label('Lokasi')->formatStateUsing(fn (GoodsReceipt $record) => $record->location->label()),
                TextEntry::make('received_at')->label('Diterima')->dateTime('d M Y H.i', $tz),
                TextEntry::make('receiver.name')->label('Penerima')->placeholder('-'),
                TextEntry::make('supplier_invoice_no')->label('No. faktur')->placeholder('-'),
                TextEntry::make('total')->label('Total')->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextEntry::make('notes')->label('Catatan')->placeholder('-')->columnSpanFull(),
            ]),
            RepeatableEntry::make('lines')->label('Barang')->columns(5)->schema([
                TextEntry::make('ingredient.name')->label('Bahan'),
                TextEntry::make('qty')->label('Jumlah')->formatStateUsing(fn ($state, GoodsReceiptLine $record) => InventoryFields::qtyWithUnit((string) $state, $record->unit_name)),
                TextEntry::make('unit_price')->label('Harga / satuan')->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextEntry::make('line_total')->label('Subtotal')->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextEntry::make('base_qty')->label('Masuk stok')
                    ->formatStateUsing(fn ($state, GoodsReceiptLine $record) => InventoryFields::qtyWithUnit((string) $state, $record->ingredient->base_unit)),
            ])->columnSpanFull(),
        ]);
    }

    /** @return Builder<GoodsReceipt> */
    public static function getEloquentQuery(): Builder
    {
        $ids = array_values(array_unique([...InventoryFields::outletIds(true), ...InventoryFields::outletIds()]));

        return parent::getEloquentQuery()->whereIn('outlet_id', $ids);
    }

    public static function canViewAny(): bool
    {
        return InventoryFields::canViewPurchasing() || InventoryFields::canView();
    }

    public static function canCreate(): bool
    {
        return InventoryFields::manageableLocations('receive') !== [];
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
            'index' => Pages\ListGoodsReceipts::route('/'),
            'create' => Pages\CreateGoodsReceipt::route('/create'),
            'view' => Pages\ViewGoodsReceipt::route('/{record}'),
        ];
    }
}
