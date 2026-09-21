<?php

namespace App\Filament\Resources;

use App\Filament\Pages\RecipeEditor;
use App\Filament\Resources\IngredientResource\Pages;
use App\Filament\Support\InventoryFields;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\RecipeLine;
use App\Modules\Inventory\Domain\Models\StockBalance;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Bahan baku & bahan setengah jadi (FR-INV-01). */
class IngredientResource extends Resource
{
    protected static ?string $model = Ingredient::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $modelLabel = 'bahan';

    protected static ?string $pluralModelLabel = 'Bahan Baku';

    protected static ?string $slug = 'bahan-baku';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Data bahan')->columns(4)->schema([
                TextInput::make('code')->label('Kode')->required()->maxLength(30)->regex('/^[A-Za-z0-9._-]+$/')
                    ->placeholder('SUSU-UHT')
                    ->rule(fn (?Ingredient $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                        $taken = Ingredient::query()->whereRaw('lower(code) = lower(?)', [(string) $value])
                            ->when($record !== null, fn ($q) => $q->whereKeyNot($record->id))->exists();
                        if ($taken) {
                            $fail('Kode bahan sudah dipakai.');
                        }
                    }),
                TextInput::make('name')->label('Nama bahan')->required()->maxLength(100)->columnSpan(2)->placeholder('Susu UHT Full Cream'),
                TextInput::make('category')->label('Kategori')->maxLength(40)->placeholder('Susu & Krim')
                    ->datalist(fn () => Ingredient::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all()),
                Select::make('base_unit')->label('Satuan dasar')->options(Ingredient::BASE_UNITS)->required()->live()
                    ->disabledOn('edit')->helperText('Resep dan stok dihitung dalam satuan ini; tidak dapat diubah.'),
                Select::make('kind')->label('Jenis')->options(Ingredient::KINDS)->default(Ingredient::RAW)->required()->disabledOn('edit')
                    ->helperText('Setengah jadi: sirup, saus, adonan. Isi sub-resepnya di menu Resep.'),
                InventoryFields::qty('min_stock', 'Stok minimum')->default('0')
                    ->suffix(fn (Get $get) => $get('base_unit'))
                    ->helperText('Muncul di peringatan stok kritis bila saldo di bawah angka ini.'),
                Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
                Textarea::make('notes')->label('Catatan')->maxLength(300)->rows(2)->columnSpanFull(),
            ]),
            Section::make('Satuan beli')->description('Contoh: 1 karton = 12.000 ml. Dipakai di purchase order dan penerimaan barang.')->schema([
                Repeater::make('units')->hiddenLabel()->relationship()
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('name')->label('Nama satuan')->required()->maxLength(20)
                                ->notIn(array_keys(Ingredient::BASE_UNITS))->placeholder('karton'),
                            InventoryFields::qty('factor', 'Isi per satuan')
                                ->suffix(fn (Get $get) => $get('../../base_unit'))
                                ->rules(['gt:0']),
                            Toggle::make('is_purchase_default')->label('Satuan utama pembelian')->inline(false),
                        ]),
                    ])
                    ->addActionLabel('Tambah satuan')
                    ->defaultItems(0)
                    ->maxItems(10)
                    ->itemLabel(fn (array $state) => $state['name'] ?? null),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['units', 'recipe']))
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('category')->label('Kategori')->placeholder('-')->sortable(),
                TextColumn::make('base_unit')->label('Satuan'),
                TextColumn::make('kind')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state, Ingredient $record) => Ingredient::KINDS[$state].($record->isSemi() && $record->recipe !== null ? ' · ada resep' : ''))
                    ->color(fn (string $state) => $state === Ingredient::SEMI ? 'info' : 'gray'),
                TextColumn::make('units')->label('Satuan beli')
                    ->state(fn (Ingredient $record) => $record->units->map(fn ($u) => $u->name.' = '.InventoryFields::number((string) $u->factor))->implode(', ') ?: '-'),
                TextColumn::make('min_stock')->label('Minimum')->alignEnd()
                    ->formatStateUsing(fn ($state, Ingredient $record) => InventoryFields::qtyWithUnit((string) $state, $record->base_unit)),
                TextColumn::make('last_cost')->label('Harga beli terakhir')->alignEnd()->placeholder('-')
                    ->formatStateUsing(fn ($state, Ingredient $record) => 'Rp'.InventoryFields::number(round((float) $state, 2)).' / '.$record->base_unit),
                TextColumn::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('category')->label('Kategori')
                    ->options(fn () => Ingredient::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category', 'category')->all()),
                SelectFilter::make('kind')->label('Jenis')->options(Ingredient::KINDS),
                TernaryFilter::make('is_active')->label('Status')->trueLabel('Aktif')->falseLabel('Nonaktif'),
            ])
            ->actions([
                Action::make('recipe')->label('Sub-resep')->icon('heroicon-o-list-bullet')
                    ->visible(fn (Ingredient $record) => $record->isSemi())
                    ->url(fn (Ingredient $record) => RecipeEditor::getUrl(['jenis' => 'ingredient', 'target' => $record->id])),
                EditAction::make()->label('Ubah'),
                DeleteAction::make()->label('Hapus')
                    ->modalHeading('Hapus bahan?')
                    ->modalDescription('Bahan yang masih dipakai resep atau masih bersaldo tidak dapat dihapus. Nonaktifkan bila tidak dipakai lagi.')
                    ->before(function (Ingredient $record, DeleteAction $action): void {
                        $message = match (true) {
                            RecipeLine::query()->where('ingredient_id', $record->id)->exists() => 'Bahan masih dipakai di resep.',
                            StockBalance::query()->where('ingredient_id', $record->id)->where('qty', '<>', 0)->exists() => 'Bahan masih memiliki saldo stok.',
                            default => null,
                        };
                        if ($message !== null) {
                            Notification::make()->danger()->title($message)->send();
                            $action->cancel();
                        }
                    }),
            ])
            ->emptyStateHeading('Belum ada bahan baku')
            ->emptyStateDescription('Tambahkan bahan seperti Biji Kopi Arabika (g), Susu Segar (ml), atau Croissant Beku (pcs).');
    }

    public static function canViewAny(): bool
    {
        $user = InventoryFields::user();

        return $user !== null && $user->can('viewAny', Ingredient::class);
    }

    public static function canCreate(): bool
    {
        return InventoryFields::canManageMaster();
    }

    public static function canEdit(Model $record): bool
    {
        return InventoryFields::canManageMaster();
    }

    public static function canDelete(Model $record): bool
    {
        return InventoryFields::canManageMaster();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIngredients::route('/'),
            'create' => Pages\CreateIngredient::route('/create'),
            'edit' => Pages\EditIngredient::route('/{record}/edit'),
        ];
    }
}
