<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ItemResource\Pages;
use App\Filament\Resources\ItemResource\RelationManagers;
use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Application\CatalogRemover;
use App\Modules\Catalog\Domain\Models\BundleGroup;
use App\Modules\Catalog\Domain\Models\BundleGroupOption;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemVariant;
use App\Modules\Catalog\Domain\Models\KitchenStation;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use App\Modules\Shared\Application\MediaStore;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/** Daftar menu: item biasa & paket, varian, modifier, channel, jadwal (FR-MENU-02..07, FR-MENU-14). */
class ItemResource extends Resource
{
    protected static ?string $model = Item::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Menu & Harga';

    protected static ?string $modelLabel = 'menu';

    protected static ?string $pluralModelLabel = 'Daftar Menu';

    protected static ?string $slug = 'menu';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('Menu')->id('menu')->columnSpanFull()->persistTabInQueryString()->tabs([
                Tabs\Tab::make('Informasi')->schema(self::infoSchema()),
                Tabs\Tab::make('Varian & Modifier')
                    ->visible(fn (Get $get) => $get('type') !== Item::TYPE_BUNDLE)
                    ->schema(self::variantSchema()),
                Tabs\Tab::make('Isi Paket')
                    ->visible(fn (Get $get) => $get('type') === Item::TYPE_BUNDLE)
                    ->schema(self::bundleSchema()),
                Tabs\Tab::make('Channel & Jadwal')->schema(self::availabilitySchema()),
            ]),
        ]);
    }

    /** @return array<int, Component> */
    private static function infoSchema(): array
    {
        return [
            Grid::make(3)->schema([
                Select::make('brand_id')->label('Brand')
                    ->options(fn () => MenuFields::manageableBrands())
                    ->in(fn () => array_keys(MenuFields::manageableBrands()), fn (string $operation) => $operation === 'create')
                    ->required()->live()
                    ->disabledOn('edit')
                    ->afterStateUpdated(function (Set $set): void {
                        $set('category_id', null);
                        $set('modifier_group_ids', []);
                        $set('bundle_groups', []);
                    }),
                Select::make('category_id')->label('Kategori')
                    ->options(fn (Get $get) => $get('brand_id')
                        ? MenuCategory::query()->where('brand_id', $get('brand_id'))->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all()
                        : [])
                    ->required()
                    ->helperText('Pilih brand terlebih dahulu.'),
                Select::make('type')->label('Jenis')
                    ->options([Item::TYPE_SINGLE => 'Menu biasa', Item::TYPE_BUNDLE => 'Paket'])
                    ->in([Item::TYPE_SINGLE, Item::TYPE_BUNDLE])
                    ->default(Item::TYPE_SINGLE)->required()->live()
                    ->disabledOn('edit'),
            ]),
            Grid::make(3)->schema([
                TextInput::make('sku')->label('SKU')->required()->maxLength(40)
                    ->regex('/^[A-Za-z0-9\-_.]+$/')
                    ->helperText('Kode unik per brand, mis. KSA-01')
                    ->rule(fn (Get $get, ?Item $record) => self::uniqueSku($get, $record)),
                TextInput::make('name')->label('Nama menu')->required()->maxLength(100),
                TextInput::make('short_name')->label('Nama di struk/KDS')->maxLength(24)
                    ->helperText('Kosongkan untuk memakai nama menu.'),
            ]),
            Grid::make(3)->schema([
                MenuFields::money('base_price', 'Harga dasar')
                    ->helperText('Harga bila tidak ada varian atau harga khusus.'),
                Select::make('kitchen_station_id')->label('Stasiun dapur')
                    ->options(fn () => KitchenStation::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->in(fn () => KitchenStation::query()->pluck('id')->all())
                    ->placeholder('Tidak dikirim ke dapur'),
                TextInput::make('barcode')->label('Barcode')->maxLength(40),
            ]),
            Grid::make(3)->schema([
                Toggle::make('sold_by_weight')->label('Dijual per berat')->inline(false)->live()
                    ->helperText('Untuk barang yang ditimbang, mis. ikan. Kasir mengisi berat, harga = harga satuan x berat.'),
                TextInput::make('unit')->label('Satuan')->maxLength(10)->default('pcs')
                    ->helperText('Satuan yang tampil di kasir dan struk, mis. kg atau pcs.')
                    ->required(fn (Get $get) => (bool) $get('sold_by_weight')),
            ]),
            Textarea::make('description')->label('Deskripsi')->rows(2)->maxLength(1000),
            self::fotoMenu(),
            Grid::make(3)->schema([
                TextInput::make('sort_order')->label('Urutan')->integer()->minValue(0)->maxValue(32767)->default(0),
                Toggle::make('is_active')->label('Dijual')->default(true)->inline(false),
            ]),
        ];
    }

    /** @return array<int, Component> */
    private static function variantSchema(): array
    {
        return [
            Repeater::make('variants')->label('Varian ukuran/porsi')
                ->helperText('Kosongkan bila menu hanya punya satu harga.')
                ->schema([
                    Hidden::make('id'),
                    Grid::make(5)->schema([
                        TextInput::make('name')->label('Nama varian')->required()->maxLength(40)->columnSpan(2),
                        MenuFields::money('price', 'Harga'),
                        TextInput::make('sku')->label('SKU varian')->maxLength(40),
                        Toggle::make('is_default')->label('Default')->inline(false),
                    ]),
                ])
                ->defaultItems(0)->maxItems(20)
                ->reorderableWithButtons()
                ->addActionLabel('Tambah varian')
                ->itemLabel(fn (array $state) => $state['name'] ?? null)
                ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                    $rows = collect(is_array($value) ? $value : []);
                    if ($rows->where('is_default', true)->count() > 1) {
                        $fail('Hanya satu varian yang boleh menjadi default.');
                    }
                    if ($rows->pluck('name')->map(fn ($n) => mb_strtolower(trim((string) $n)))->duplicates()->isNotEmpty()) {
                        $fail('Nama varian tidak boleh sama.');
                    }
                }),
            CheckboxList::make('modifier_group_ids')->label('Grup modifier')
                ->options(fn (Get $get) => $get('brand_id')
                    ? ModifierGroup::query()->where('brand_id', $get('brand_id'))->where('is_active', true)->orderBy('name')->get()
                        ->mapWithKeys(fn (ModifierGroup $g) => [$g->id => $g->name.($g->min_select > 0 ? ' (wajib)' : '')])->all()
                    : [])
                ->columns(3)
                ->helperText('Urutan tampil di kasir mengikuti urutan grup pada halaman Modifier.'),
        ];
    }

    /** @return array<int, Component> */
    private static function bundleSchema(): array
    {
        $singleItems = fn (Get $get): array => ($brand = $get('../../../../brand_id'))
            ? Item::query()->where('brand_id', $brand)->where('type', Item::TYPE_SINGLE)->orderBy('name')->pluck('name', 'id')->all()
            : [];

        return [
            Placeholder::make('bundle_help')->hiddenLabel()
                ->content('Harga paket diisi pada Harga dasar. Tambahan harga per pilihan diisi pada kolom Tambahan.'),
            Repeater::make('bundle_groups')->label('Grup pilihan paket')
                ->schema([
                    Grid::make(4)->schema([
                        TextInput::make('name')->label('Nama grup')->required()->maxLength(60)->placeholder('Minuman')->columnSpan(2),
                        TextInput::make('min_select')->label('Minimal')->integer()->minValue(0)->maxValue(20)->default(1)->required(),
                        TextInput::make('max_select')->label('Maksimal')->integer()->minValue(1)->maxValue(20)->default(1)->required()->gte('min_select'),
                    ]),
                    Repeater::make('options')->label('Pilihan')
                        ->schema([
                            Grid::make(4)->schema([
                                Select::make('item_id')->label('Menu')->options($singleItems)->required()->live()
                                    ->columnSpan(2),
                                Select::make('item_variant_id')->label('Varian')
                                    ->options(fn (Get $get) => $get('item_id')
                                        ? ItemVariant::query()->where('item_id', $get('item_id'))->pluck('name', 'id')->all()
                                        : [])
                                    ->in(fn (Get $get) => $get('item_id') ? ItemVariant::query()->where('item_id', $get('item_id'))->pluck('id')->all() : [])
                                    ->placeholder('Default'),
                                MenuFields::money('extra_price', 'Tambahan', false)->default('0'),
                            ]),
                            Toggle::make('is_default')->label('Terpilih otomatis'),
                        ])
                        ->minItems(1)->maxItems(50)->defaultItems(1)
                        ->addActionLabel('Tambah pilihan'),
                ])
                ->defaultItems(0)->maxItems(10)
                ->reorderableWithButtons()
                ->addActionLabel('Tambah grup')
                ->itemLabel(fn (array $state) => $state['name'] ?? null)
                ->requiredIf('type', Item::TYPE_BUNDLE)
                ->validationMessages(['required_if' => 'Paket harus memiliki minimal satu grup pilihan.']),
        ];
    }

    /** @return array<int, Component> */
    private static function availabilitySchema(): array
    {
        return [
            CheckboxList::make('channel_codes')->label('Dijual di channel')
                ->options(fn () => MenuFields::channels())
                ->nestedRecursiveRules([MenuFields::knownChannel()])
                ->columns(4)
                ->helperText('Kosongkan untuk semua channel.'),
            Repeater::make('schedule')->label('Jadwal jual')
                ->helperText('Kosongkan bila menu dijual sepanjang jam buka. Jam mengikuti zona waktu outlet.')
                ->schema([
                    Grid::make(3)->schema([
                        CheckboxList::make('days')->label('Hari')->options(MenuFields::DAYS)->nestedRecursiveRules([Rule::in(array_keys(MenuFields::DAYS))])->columns(4)->columnSpan(3)
                            ->helperText('Kosongkan untuk setiap hari.'),
                        TimePicker::make('start')->label('Mulai')->seconds(false)->required(),
                        TimePicker::make('end')->label('Selesai')->seconds(false)->required()->different('start')
                            ->helperText('Boleh melewati tengah malam, mis. 22:00–02:00.'),
                    ]),
                ])
                ->defaultItems(0)->maxItems(14)
                ->addActionLabel('Tambah jadwal'),
        ];
    }

    /**
     * Foto menu yang tampil sebagai kartu di layar kasir (FR-MENU-02).
     *
     * Berkasnya diperkecil dua kali: sekali di browser sebelum diunggah (menghemat kuota kasir
     * yang mengunggah dari ponsel), sekali lagi di server lewat MediaStore — yang terakhir inilah
     * yang menentukan, karena unggahan lewat API tidak melewati browser.
     */
    private static function fotoMenu(): FileUpload
    {
        return FileUpload::make('image_path')
            ->label('Foto menu')
            ->helperText('JPG, PNG, atau WebP. Otomatis dipotong 4:3 dan diperkecil ke 800 x 600 — tidak perlu diedit dulu.')
            ->image()
            ->imageEditor()
            ->imageCropAspectRatio('4:3')
            ->imageResizeMode('cover')
            ->imageResizeTargetWidth((string) config('fnb.media.item_width'))
            ->imageResizeTargetHeight((string) config('fnb.media.item_height'))
            ->maxSize((int) config('fnb.media.max_upload_kb'))
            ->disk(fn () => app(MediaStore::class)->diskName())
            ->directory('menu')
            ->visibility('public')
            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file) => app(MediaStore::class)->put($file, 'menu'))
            // Berkas lama dibuang ItemWriter setelah penyimpanan berhasil; di sini hanya
            // penghapusan yang diminta pengguna secara langsung.
            ->deleteUploadedFileUsing(fn (?string $file) => app(MediaStore::class)->forget($file));
    }

    private static function uniqueSku(Get $get, ?Item $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
            $brandId = $record->brand_id ?? $get('brand_id');
            $exists = is_string($value) && $brandId && DB::table('items')
                ->where('brand_id', $brandId)->whereNull('deleted_at')
                ->whereRaw('lower(sku) = lower(?)', [trim($value)])
                ->when($record !== null, fn ($q) => $q->where('id', '!=', $record->id))
                ->exists();
            if ($exists) {
                $fail('SKU sudah dipakai di brand ini.');
            }
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['brand', 'category', 'station'])->withCount('variants'))
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable()
                    ->description(fn (Item $record) => $record->sku),
                // Placeholder wajib: sel kosong dirender sebagai tautan tanpa teks, yang gagal
                // WCAG "link-name" dan tidak terbaca pembaca layar.
                TextColumn::make('category.name')->label('Kategori')->sortable()->placeholder('—'),
                TextColumn::make('brand.name')->label('Brand')->toggleable()->placeholder('—'),
                TextColumn::make('base_price')->label('Harga')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('variants_count')->label('Varian')->numeric()->alignEnd()->toggleable(),
                TextColumn::make('type')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state) => $state === Item::TYPE_BUNDLE ? 'Paket' : 'Menu')
                    ->color(fn (string $state) => $state === Item::TYPE_BUNDLE ? 'info' : 'gray'),
                TextColumn::make('station.name')->label('Stasiun')->toggleable()->placeholder('—'),
                TextColumn::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Dijual' : 'Tidak dijual')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
                TextColumn::make('updated_at')->label('Diubah')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->searchPlaceholder('Cari nama atau SKU')
            ->filters([
                SelectFilter::make('brand_id')->label('Brand')->options(fn () => MenuFields::visibleBrands()),
                SelectFilter::make('category_id')->label('Kategori')
                    ->options(fn () => MenuFields::scopeQuery(MenuCategory::query())->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('type')->label('Jenis')->options([Item::TYPE_SINGLE => 'Menu biasa', Item::TYPE_BUNDLE => 'Paket']),
                TernaryFilter::make('is_active')->label('Dijual'),
            ])
            ->actions([
                EditAction::make()->label('Ubah'),
                ActionGroup::make([
                    DeleteAction::make()->label('Hapus')
                        ->modalHeading('Hapus menu?')
                        ->modalDescription('Menu dihapus dari daftar jual. Riwayat transaksi dan harga tetap tersimpan.')
                        ->using(function (Item $record): bool {
                            MenuFields::run(fn () => app(CatalogRemover::class)->item($record));

                            return true;
                        }),
                ])->label('Aksi lainnya')->tooltip('Aksi lainnya')->extraAttributes(['aria-label' => 'Aksi lainnya']),
            ])
            ->emptyStateHeading('Belum ada menu')
            ->emptyStateDescription('Tambahkan menu satu per satu atau impor dari file Excel.');
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

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'sku'];
    }

    /**
     * Isi form dari data tersimpan (relasi → array).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillData(Item $item, array $data): array
    {
        $item->load(['variants', 'modifierGroups', 'bundleGroups.options']);
        $data['base_price'] = MenuFields::plain((string) $item->base_price);
        $data['variants'] = $item->variants->map(fn (ItemVariant $v) => [
            'id' => $v->id, 'name' => $v->name, 'sku' => $v->sku, 'price' => MenuFields::plain((string) $v->price),
            'is_default' => $v->is_default,
        ])->all();
        $data['modifier_group_ids'] = $item->modifierGroups->pluck('id')->all();
        $data['bundle_groups'] = $item->bundleGroups->map(fn (BundleGroup $g) => [
            'name' => $g->name, 'min_select' => $g->min_select, 'max_select' => $g->max_select,
            'options' => $g->options->map(fn (BundleGroupOption $o) => [
                'item_id' => $o->item_id, 'item_variant_id' => $o->item_variant_id,
                'extra_price' => MenuFields::plain((string) $o->extra_price), 'is_default' => $o->is_default,
            ])->all(),
        ])->all();
        $data['channel_codes'] = $item->channel_codes ?? [];
        $data['schedule'] = $item->schedule ?? [];

        return $data;
    }

    /**
     * Rapikan data form sebelum dikirim ke ItemWriter.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareData(array $data): array
    {
        $data['channel_codes'] = empty($data['channel_codes']) ? null : array_values($data['channel_codes']);
        $data['schedule'] = empty($data['schedule']) ? null : array_values(array_map(fn (array $w) => [
            'days' => empty($w['days']) ? null : array_map('intval', array_values($w['days'])),
            'start' => substr((string) $w['start'], 0, 5),
            'end' => substr((string) $w['end'], 0, 5),
        ], $data['schedule']));
        $data['variants'] = array_values(array_map(fn (array $v) => array_filter($v, fn ($x) => $x !== null && $x !== ''), $data['variants'] ?? []));
        $data['modifier_group_ids'] = empty($data['modifier_group_ids']) ? [] : ModifierGroup::query()
            ->whereIn('id', $data['modifier_group_ids'])
            ->orderBy('sort_order')->orderBy('name')
            ->pluck('id')->all();
        $data['bundle_groups'] = array_values(array_map(fn (array $g) => $g + ['options' => array_values($g['options'] ?? [])], $data['bundle_groups'] ?? []));
        if (($data['type'] ?? null) === Item::TYPE_BUNDLE) {
            $data['variants'] = [];
            $data['modifier_group_ids'] = [];
        }
        if (empty($data['short_name'])) {
            $data['short_name'] = mb_substr((string) $data['name'], 0, 24);
        }

        return $data;
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PricesRelationManager::class,
            RelationManagers\PriceHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListItems::route('/'),
            'create' => Pages\CreateItem::route('/create'),
            'edit' => Pages\EditItem::route('/{record}/edit'),
        ];
    }
}
