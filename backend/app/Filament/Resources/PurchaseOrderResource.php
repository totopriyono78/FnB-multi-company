<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseOrderResource\Pages;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Purchasing\Application\PurchaseOrderService;
use App\Modules\Purchasing\Domain\Models\PurchaseOrder;
use App\Modules\Purchasing\Domain\Models\PurchaseOrderLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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

/** Purchase order: ajukan → setujui → terima barang (FR-PUR). */
class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Pembelian';

    protected static ?string $modelLabel = 'purchase order';

    protected static ?string $pluralModelLabel = 'Purchase Order';

    protected static ?string $slug = 'purchase-order';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->columns(3)->schema([
                Select::make('location_id')->label('Dikirim ke')->required()->disabledOn('edit')
                    ->options(fn () => InventoryFields::manageableLocations('purchase')),
                Select::make('supplier_id')->label('Pemasok')->required()->searchable()
                    ->options(fn () => InventoryFields::supplierOptions()),
                DatePicker::make('expected_date')->label('Perkiraan tiba')->minDate(now()->startOfDay()),
                Textarea::make('notes')->label('Catatan untuk pemasok')->rows(1)->maxLength(500)->columnSpanFull(),
            ]),
            Section::make('Barang dipesan')->schema([
                Repeater::make('lines')->label('Daftar bahan')->hiddenLabel()
                    ->schema([
                        Grid::make(12)->schema([
                            Select::make('ingredient_id')->label('Bahan')->options(fn () => InventoryFields::ingredientOptions(true))
                                ->searchable()->required()->live()->columnSpan(4)
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                ->afterStateUpdated(fn (callable $set, ?string $state) => $set('unit_name', array_key_last(InventoryFields::unitOptions($state)))),
                            Select::make('unit_name')->label('Satuan')->required()->columnSpan(3)
                                ->options(fn (Get $get) => InventoryFields::unitOptions($get('ingredient_id'))),
                            InventoryFields::qty('qty', 'Jumlah')->rules(['gt:0'])->columnSpan(2)->live(onBlur: true),
                            MenuFields::money('unit_price', 'Harga / satuan')->columnSpan(3)->live(onBlur: true),
                        ]),
                    ])
                    ->addActionLabel('Tambah bahan')
                    ->minItems(1)->maxItems(200)->defaultItems(1),
                Placeholder::make('estimated_total')->label('Perkiraan total')
                    ->content(function (Get $get): string {
                        $total = BigDecimal::zero();
                        foreach ((array) $get('lines') as $line) {
                            if (is_numeric($line['qty'] ?? null) && is_numeric($line['unit_price'] ?? null)) {
                                $total = $total->plus(BigDecimal::of((string) $line['qty'])->multipliedBy((string) $line['unit_price']));
                            }
                        }

                        return MenuFields::rupiah((string) $total->toScale(2, RoundingMode::HALF_UP)).' (belum termasuk pajak pembelian)';
                    }),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier:id,name', 'location.outlet:id,name', 'creator:id,name']))
            ->columns([
                TextColumn::make('order_date')->label('Tanggal')->date('d M Y')->sortable(),
                TextColumn::make('number')->label('Nomor')->fontFamily('mono')->searchable(),
                TextColumn::make('supplier.name')->label('Pemasok'),
                TextColumn::make('location_id')->label('Tujuan')->formatStateUsing(fn (PurchaseOrder $r) => $r->location->label()),
                TextColumn::make('total')->label('Total')->alignEnd()->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => InventoryFields::poStatus($state))
                    ->color(fn (string $state) => InventoryFields::PO_COLORS[$state] ?? 'gray'),
                TextColumn::make('expected_date')->label('Perkiraan tiba')->date('d M Y')->placeholder('-'),
                TextColumn::make('creator.name')->label('Dibuat oleh')->placeholder('-')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Status')->options(PurchaseOrder::STATUSES)->multiple(),
                SelectFilter::make('outlet_id')->label('Outlet')->options(fn () => InventoryFields::outletOptions(true)),
                SelectFilter::make('supplier_id')->label('Pemasok')->options(fn () => InventoryFields::supplierOptions()),
            ])
            ->actions([ViewAction::make()->label('Buka')])
            ->emptyStateHeading('Belum ada purchase order')
            ->emptyStateDescription('Buat PO untuk memesan bahan ke pemasok; barang diterima setelah PO disetujui.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $tz = (string) config('app.display_timezone');

        return $infolist->schema([
            InfoSection::make()->columns(4)->schema([
                TextEntry::make('number')->label('Nomor'),
                TextEntry::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => InventoryFields::poStatus($state))
                    ->color(fn (string $state) => InventoryFields::PO_COLORS[$state] ?? 'gray'),
                TextEntry::make('supplier.name')->label('Pemasok'),
                TextEntry::make('location_id')->label('Dikirim ke')->formatStateUsing(fn (PurchaseOrder $record) => $record->location->label()),
                TextEntry::make('order_date')->label('Tanggal PO')->date('d M Y'),
                TextEntry::make('expected_date')->label('Perkiraan tiba')->date('d M Y')->placeholder('-'),
                TextEntry::make('total')->label('Total')->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextEntry::make('creator.name')->label('Dibuat oleh')->placeholder('-'),
                TextEntry::make('decided_at')->label('Diputuskan')->dateTime('d M Y H.i', $tz)->placeholder('-'),
                TextEntry::make('decision_note')->label('Catatan keputusan')->placeholder('-'),
                TextEntry::make('cancel_reason')->label('Alasan batal')->visible(fn (PurchaseOrder $record) => $record->cancel_reason !== null),
                TextEntry::make('notes')->label('Catatan')->placeholder('-')->columnSpan(2),
            ]),
            RepeatableEntry::make('lines')->label('Barang')->columns(5)->schema([
                TextEntry::make('ingredient.name')->label('Bahan'),
                TextEntry::make('qty')->label('Dipesan')->formatStateUsing(fn ($state, PurchaseOrderLine $record) => InventoryFields::qtyWithUnit((string) $state, $record->unit_name)),
                TextEntry::make('unit_price')->label('Harga / satuan')->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextEntry::make('line_total')->label('Subtotal')->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextEntry::make('received_qty')->label('Diterima')
                    ->formatStateUsing(fn ($state, PurchaseOrderLine $record) => InventoryFields::qtyWithUnit((string) $state, $record->unit_name))
                    ->color(fn ($state, PurchaseOrderLine $record) => (float) $state >= (float) $record->qty ? 'success' : null),
            ])->columnSpanFull(),
        ]);
    }

    /** @return Builder<PurchaseOrder> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('outlet_id', InventoryFields::outletIds(true));
    }

    public static function canViewAny(): bool
    {
        return InventoryFields::canViewPurchasing();
    }

    public static function canCreate(): bool
    {
        return InventoryFields::manageableLocations('purchase') !== [];
    }

    public static function canEdit(Model $record): bool
    {
        $user = InventoryFields::user();

        return $record instanceof PurchaseOrder && $user !== null && $record->isEditable()
            && app(PurchaseOrderService::class)->canRequesterAct($user, $record);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'view' => Pages\ViewPurchaseOrder::route('/{record}'),
            'edit' => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
