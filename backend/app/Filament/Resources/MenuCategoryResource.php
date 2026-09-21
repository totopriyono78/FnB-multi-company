<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MenuCategoryResource\Pages;
use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Application\CatalogRemover;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Http\Requests\MenuCategoryRequest;
use Closure;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** FR-MENU-01 */
class MenuCategoryResource extends Resource
{
    protected static ?string $model = MenuCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Menu & Harga';

    protected static ?string $modelLabel = 'kategori';

    protected static ?string $pluralModelLabel = 'Kategori Menu';

    protected static ?string $slug = 'kategori-menu';

    protected static ?int $navigationSort = 2;

    public const COLOR_LABELS = [
        'gray' => 'Abu-abu', 'red' => 'Merah', 'orange' => 'Oranye', 'amber' => 'Kuning tua', 'green' => 'Hijau',
        'teal' => 'Hijau toska', 'sky' => 'Biru muda', 'blue' => 'Biru', 'violet' => 'Ungu', 'pink' => 'Merah muda',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('brand_id')->label('Brand')
                ->options(fn () => MenuFields::manageableBrands())
                ->required()
                ->disabledOn('edit')
                ->in(fn () => array_keys(MenuFields::manageableBrands()), fn (string $operation) => $operation === 'create'),
            TextInput::make('name')->label('Nama kategori')->required()->maxLength(60)
                ->rule(fn (Get $get, ?MenuCategory $record) => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                    $brandId = $record->brand_id ?? $get('brand_id');
                    $exists = is_string($value) && $brandId && DB::table('menu_categories')
                        ->where('brand_id', $brandId)->whereNull('deleted_at')
                        ->whereRaw('lower(name) = lower(?)', [trim($value)])
                        ->when($record !== null, fn ($q) => $q->where('id', '!=', $record->id))
                        ->exists();
                    if ($exists) {
                        $fail('Nama kategori sudah dipakai di brand ini.');
                    }
                }),
            Select::make('color')->label('Warna tombol di POS')
                ->options(array_intersect_key(self::COLOR_LABELS, array_flip(MenuCategoryRequest::COLORS)))
                ->in(MenuCategoryRequest::COLORS)->default('gray')->required(),
            TextInput::make('sort_order')->label('Urutan')->integer()->minValue(0)->maxValue(32767)->default(0),
            Toggle::make('is_active')->label('Tampil di POS')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('brand')->withCount('items'))
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('brand.name')->label('Brand')->sortable(),
                TextColumn::make('color')->label('Warna')->badge()
                    ->formatStateUsing(fn (string $state) => self::COLOR_LABELS[$state] ?? $state)
                    ->color('gray'),
                TextColumn::make('items_count')->label('Menu')->numeric()->alignEnd(),
                TextColumn::make('sort_order')->label('Urutan')->numeric()->alignEnd()->sortable(),
                TextColumn::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Tampil' : 'Disembunyikan')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('brand_id')->label('Brand')->options(fn () => MenuFields::visibleBrands()),
                TernaryFilter::make('is_active')->label('Tampil di POS'),
            ])
            ->actions([
                EditAction::make()->label('Ubah'),
                DeleteAction::make()->label('Hapus')
                    ->modalHeading('Hapus kategori?')
                    ->modalDescription('Kategori hanya bisa dihapus bila tidak berisi menu.')
                    ->using(function (MenuCategory $record): bool {
                        MenuFields::run(fn () => app(CatalogRemover::class)->category($record));

                        return true;
                    }),
            ])
            ->emptyStateHeading('Belum ada kategori')
            ->emptyStateDescription('Kategori mengelompokkan menu di layar kasir, misalnya Kopi, Non-Kopi, dan Makanan.');
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMenuCategories::route('/'),
            'create' => Pages\CreateMenuCategory::route('/create'),
            'edit' => Pages\EditMenuCategory::route('/{record}/edit'),
        ];
    }
}
