<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ModifierGroupResource\Pages;
use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Application\CatalogRemover;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Grup pilihan tambahan: tingkat gula, topping, level pedas (FR-MENU-04). */
class ModifierGroupResource extends Resource
{
    protected static ?string $model = ModifierGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Menu & Harga';

    protected static ?string $modelLabel = 'grup modifier';

    protected static ?string $pluralModelLabel = 'Modifier';

    protected static ?string $slug = 'modifier';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Grup')->columns(4)->schema([
                Select::make('brand_id')->label('Brand')
                    ->options(fn () => MenuFields::manageableBrands())
                    ->in(fn () => array_keys(MenuFields::manageableBrands()), fn (string $operation) => $operation === 'create')
                    ->required()->disabledOn('edit'),
                TextInput::make('name')->label('Nama grup')->required()->maxLength(60)->placeholder('Tingkat Gula'),
                TextInput::make('min_select')->label('Minimal dipilih')->integer()->minValue(0)->maxValue(50)->default(0)->required()
                    ->helperText('Isi 1 bila wajib dipilih.'),
                TextInput::make('max_select')->label('Maksimal dipilih')->integer()->minValue(1)->maxValue(50)->default(1)->required()
                    ->gte('min_select'),
                TextInput::make('sort_order')->label('Urutan di kasir')->integer()->minValue(0)->maxValue(32767)->default(0),
                Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
            ]),
            Section::make('Pilihan')->schema([
                Repeater::make('modifiers')->hiddenLabel()
                    ->schema([
                        Hidden::make('id'),
                        Grid::make(4)->schema([
                            TextInput::make('name')->label('Nama pilihan')->required()->maxLength(60)->columnSpan(2),
                            MenuFields::money('price', 'Harga tambahan')->default('0'),
                            Toggle::make('is_default')->label('Terpilih otomatis')->inline(false),
                        ]),
                    ])
                    ->addActionLabel('Tambah pilihan')
                    ->reorderableWithButtons()
                    ->minItems(1)->maxItems(100)
                    ->defaultItems(1)
                    ->itemLabel(fn (array $state) => $state['name'] ?? null)
                    ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                        $defaults = collect(is_array($value) ? $value : [])->where('is_default', true)->count();
                        if ($defaults > (int) $get('max_select')) {
                            $fail('Pilihan otomatis melebihi batas maksimal dipilih.');
                        }
                    }),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['brand', 'modifiers']))
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('brand.name')->label('Brand'),
                TextColumn::make('modifiers')->label('Pilihan')
                    ->state(fn (ModifierGroup $record) => $record->modifiers->pluck('name')->implode(', '))
                    ->limit(60)->wrap(),
                TextColumn::make('rule')->label('Aturan')
                    ->state(fn (ModifierGroup $record) => ($record->min_select > 0 ? 'Wajib' : 'Opsional').", maks. {$record->max_select}"),
                TextColumn::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->defaultSort('name')
            ->filters([SelectFilter::make('brand_id')->label('Brand')->options(fn () => MenuFields::visibleBrands())])
            ->actions([
                EditAction::make()->label('Ubah'),
                DeleteAction::make()->label('Hapus')
                    ->modalHeading('Hapus grup modifier?')
                    ->using(function (ModifierGroup $record): bool {
                        MenuFields::run(fn () => app(CatalogRemover::class)->modifierGroup($record));

                        return true;
                    }),
            ])
            ->emptyStateHeading('Belum ada grup modifier')
            ->emptyStateDescription('Contoh: Tingkat Gula (Normal, Less, No Sugar) atau Topping (Boba, Cheese Foam).');
    }

    /**
     * Query dari Filament (tenant aktif) dipersempit ke brand yang boleh dilihat user.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return MenuFields::scopeQuery(parent::getEloquentQuery());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillData(ModifierGroup $record, array $data): array
    {
        $data['modifiers'] = $record->modifiers()->orderBy('sort_order')->get()->map(fn ($m) => [
            'id' => $m->id, 'name' => $m->name, 'price' => MenuFields::plain((string) $m->price), 'is_default' => $m->is_default,
        ])->all();

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListModifierGroups::route('/'),
            'create' => Pages\CreateModifierGroup::route('/create'),
            'edit' => Pages\EditModifierGroup::route('/{record}/edit'),
        ];
    }
}
