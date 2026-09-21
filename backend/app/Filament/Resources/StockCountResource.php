<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockCountResource\Pages;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Inventory\Domain\Models\StockCount;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Stock opname penuh/sebagian dengan persetujuan manajer (FR-INV-06). */
class StockCountResource extends Resource
{
    protected static ?string $model = StockCount::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $modelLabel = 'stock opname';

    protected static ?string $pluralModelLabel = 'Stock Opname';

    protected static ?string $slug = 'stock-opname';

    protected static ?int $navigationSort = 7;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->columns(2)->schema([
                Select::make('location_id')->label('Lokasi')->options(fn () => InventoryFields::manageableLocations())->required(),
                Select::make('scope')->label('Jenis opname')->required()->live()->default('full')
                    ->options(['full' => 'Penuh (semua bahan)', 'partial' => 'Sebagian (bahan terpilih)']),
                Select::make('ingredient_ids')->label('Bahan yang dihitung')->multiple()->searchable()
                    ->options(fn () => InventoryFields::ingredientOptions(true))
                    ->visible(fn (Get $get) => $get('scope') === 'partial')
                    ->required(fn (Get $get) => $get('scope') === 'partial')
                    ->columnSpanFull(),
                Textarea::make('notes')->label('Catatan')->rows(2)->maxLength(300)->columnSpanFull()
                    ->placeholder('Opname akhir bulan, dihitung sebelum outlet buka'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('location.outlet:id,name')->withCount('lines'))
            ->columns([
                TextColumn::make('started_at')->label('Dimulai')->dateTime('d M Y H.i', $tz)->sortable(),
                TextColumn::make('number')->label('Nomor')->fontFamily('mono')->searchable(),
                TextColumn::make('location_id')->label('Lokasi')->formatStateUsing(fn (StockCount $r) => $r->location->label()),
                TextColumn::make('scope')->label('Jenis')->formatStateUsing(fn (string $state) => $state === 'full' ? 'Penuh' : 'Sebagian'),
                TextColumn::make('lines_count')->label('Bahan')->alignEnd(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => StockCount::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => InventoryFields::COUNT_COLORS[$state] ?? 'gray'),
                TextColumn::make('variance_value')->label('Nilai selisih')->alignEnd()->placeholder('-')
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state))
                    ->color(fn ($state) => $state !== null && (float) $state < 0 ? 'danger' : null),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Status')->options(StockCount::STATUSES),
                SelectFilter::make('outlet_id')->label('Outlet')->options(fn () => InventoryFields::outletOptions()),
            ])
            ->actions([ViewAction::make()->label('Buka')])
            ->emptyStateHeading('Belum ada stock opname')
            ->emptyStateDescription('Mulai opname untuk membandingkan stok sistem dengan jumlah fisik di gudang.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $tz = (string) config('app.display_timezone');

        return $infolist->schema([
            InfoSection::make()->columns(4)->schema([
                TextEntry::make('number')->label('Nomor'),
                TextEntry::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => StockCount::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => InventoryFields::COUNT_COLORS[$state] ?? 'gray'),
                TextEntry::make('location_id')->label('Lokasi')->formatStateUsing(fn (StockCount $record) => $record->location->label()),
                TextEntry::make('started_at')->label('Saldo sistem dibekukan')->dateTime('d M Y H.i', $tz),
                TextEntry::make('variance_value')->label('Nilai selisih')->placeholder('Belum diajukan')->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextEntry::make('notes')->label('Catatan')->placeholder('-'),
                TextEntry::make('decision_note')->label('Catatan manajer')->placeholder('-'),
            ]),
            // Hitung buta: saldo sistem & selisih disembunyikan selama tahap hitung (FR-POS-03 diterapkan pada opname).
            InfoSection::make('Hasil hitung')->schema([
                ViewEntry::make('lines')->hiddenLabel()->view('filament.infolists.stock-count-lines'),
            ]),
        ]);
    }

    /** @return Builder<StockCount> */
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
            'index' => Pages\ListStockCounts::route('/'),
            'create' => Pages\CreateStockCount::route('/create'),
            'view' => Pages\ViewStockCount::route('/{record}'),
        ];
    }
}
