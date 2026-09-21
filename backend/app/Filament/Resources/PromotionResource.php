<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PromotionResource\Pages;
use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Application\CatalogRemover;
use App\Modules\Catalog\Application\MenuScope;
use App\Modules\Catalog\Application\PromotionRules;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\Promotion;
use App\Modules\Catalog\Http\Requests\PromotionRequest;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

/** Promo & diskon terjadwal (FR-MENU-11, FR-MENU-12, BR-18). */
class PromotionResource extends Resource
{
    protected static ?string $model = Promotion::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Menu & Harga';

    protected static ?string $modelLabel = 'promo';

    protected static ?string $pluralModelLabel = 'Promo';

    protected static ?string $slug = 'promo';

    protected static ?int $navigationSort = 4;

    public const TYPES = [
        'percent' => 'Diskon persen',
        'amount' => 'Potongan nominal',
        'buy_x_get_y' => 'Beli X gratis Y',
        'special_price' => 'Harga spesial',
    ];

    public const PAYMENT_LABELS = [
        'cash' => 'Tunai', 'qris' => 'QRIS', 'debit' => 'Kartu debit', 'credit' => 'Kartu kredit',
        'ewallet' => 'E-wallet', 'transfer' => 'Transfer', 'voucher' => 'Voucher',
        'member_balance' => 'Saldo member', 'city_ledger' => 'Piutang',
    ];

    public static function form(Form $form): Form
    {
        $brandOptions = fn (): array => MenuFields::manageableBrands();
        $companyWide = fn (): bool => MenuFields::user()?->can('menu.manage') === true
            && app(MenuScope::class)->brandIds(MenuFields::user()) === null;

        return $form->schema([
            Section::make('Promo')->columns(3)->schema([
                TextInput::make('name')->label('Nama promo')->required()->maxLength(80)->columnSpan(2),
                Select::make('brand_id')->label('Brand')
                    ->options($brandOptions)
                    ->placeholder('Semua brand')
                    ->required(fn () => ! $companyWide())
                    ->in(fn () => array_keys($brandOptions()), fn (string $operation) => $operation === 'create')
                    ->live()->disabledOn('edit'),
                Select::make('type')->label('Jenis')->options(self::TYPES)->in(array_keys(self::TYPES))->required()->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if (in_array($state, ['buy_x_get_y', 'special_price'], true)) {
                            $set('scope', 'items');
                        }
                    }),
                Select::make('scope')->label('Berlaku untuk')
                    ->options(['order' => 'Total belanja', 'items' => 'Menu/kategori tertentu'])
                    ->in(['order', 'items'])
                    ->default('order')->required()->live()
                    ->disabled(fn (Get $get) => in_array($get('type'), ['buy_x_get_y', 'special_price'], true))
                    ->dehydrated(),
                TextInput::make('value')->label(fn (Get $get) => match ($get('type')) {
                    'percent' => 'Diskon (%)',
                    'special_price' => 'Harga spesial (Rp)',
                    default => 'Potongan (Rp)',
                })
                    ->inputMode('decimal')->rules(['decimal:0,2', 'min:0', 'max:9999999999'])
                    ->required(fn (Get $get) => $get('type') !== 'buy_x_get_y')
                    ->hidden(fn (Get $get) => $get('type') === 'buy_x_get_y')
                    ->formatStateUsing(fn ($state) => $state === null ? null : MenuFields::plain((string) $state)),
                TextInput::make('buy_qty')->label('Jumlah beli')->integer()->minValue(1)->maxValue(100)
                    ->visible(fn (Get $get) => $get('type') === 'buy_x_get_y')->required(fn (Get $get) => $get('type') === 'buy_x_get_y'),
                TextInput::make('get_qty')->label('Jumlah gratis')->integer()->minValue(1)->maxValue(100)
                    ->visible(fn (Get $get) => $get('type') === 'buy_x_get_y')->required(fn (Get $get) => $get('type') === 'buy_x_get_y'),
            ]),
            Section::make('Menu yang mendapat promo')
                ->visible(fn (Get $get) => $get('scope') === 'items')
                ->columns(2)->schema([
                    CheckboxList::make('item_ids')->label('Menu')->searchable()->columns(2)->bulkToggleable()
                        ->options(fn (Get $get) => MenuFields::scopeQuery(Item::query())
                            ->when($get('brand_id'), fn (Builder $q, $b) => $q->where('brand_id', $b))
                            ->orderBy('name')->limit(500)->pluck('name', 'id')->all()),
                    CheckboxList::make('category_ids')->label('Kategori')->columns(2)
                        ->options(fn (Get $get) => MenuFields::scopeQuery(MenuCategory::query())
                            ->when($get('brand_id'), fn (Builder $q, $b) => $q->where('brand_id', $b))
                            ->orderBy('name')->pluck('name', 'id')->all()),
                ]),
            Section::make('Syarat')->columns(3)->schema([
                MenuFields::money('min_purchase', 'Minimal belanja', false),
                MenuFields::money('max_discount', 'Maksimal potongan', false)
                    ->helperText('Untuk diskon persen.'),
                TextInput::make('quota')->label('Kuota pemakaian')->integer()->minValue(1)->maxValue(1000000000)->placeholder('Tanpa batas'),
                CheckboxList::make('outlet_ids')->label('Outlet')
                    ->options(fn (Get $get) => MenuFields::outlets($get('brand_id') ?: null))
                    ->helperText('Kosongkan untuk semua outlet.'),
                CheckboxList::make('channel_codes')->label('Channel')->options(fn () => MenuFields::channels())->nestedRecursiveRules([MenuFields::knownChannel()])->columns(2)
                    ->helperText('Kosongkan untuk semua channel.'),
                CheckboxList::make('payment_methods')->label('Metode bayar')
                    ->options(array_intersect_key(self::PAYMENT_LABELS, array_flip(PromotionRequest::PAYMENT_METHODS)))->nestedRecursiveRules([Rule::in(PromotionRequest::PAYMENT_METHODS)])->columns(2)
                    ->helperText('Kosongkan untuk semua metode.'),
            ]),
            Section::make('Jadwal')->columns(4)->schema([
                DateTimePicker::make('starts_at')->label('Mulai')->seconds(false)->required()->default(now()),
                DateTimePicker::make('ends_at')->label('Berakhir')->seconds(false)->after('starts_at'),
                TimePicker::make('time_start')->label('Jam mulai (happy hour)')->seconds(false)->requiredWith('time_end'),
                TimePicker::make('time_end')->label('Jam selesai')->seconds(false)->requiredWith('time_start')->different('time_start'),
                CheckboxList::make('days_of_week')->label('Hari')->options(MenuFields::DAYS)->nestedRecursiveRules([Rule::in(array_keys(MenuFields::DAYS))])->columns(7)->columnSpanFull()
                    ->helperText('Kosongkan untuk setiap hari.'),
            ]),
            Section::make('Penerapan')->columns(4)->schema([
                Toggle::make('auto_apply')->label('Otomatis di kasir')->default(true)->live()->inline(false),
                TextInput::make('code')->label('Kode promo')->maxLength(30)
                    ->regex('/^[A-Z0-9\-]+$/')
                    ->mutateStateForValidationUsing(fn (?string $state) => $state === null ? null : strtoupper(trim($state)))
                    ->dehydrateStateUsing(fn (?string $state) => $state === null || trim($state) === '' ? null : strtoupper(trim($state)))
                    ->extraInputAttributes(['class' => 'uppercase'])
                    ->required(fn (Get $get) => ! $get('auto_apply'))
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('company_id', filament()->getTenant()?->getKey())->whereNull('deleted_at')),
                Toggle::make('stackable')->label('Bisa digabung promo lain')->inline(false)
                    ->helperText('Promo yang tidak bisa digabung dibandingkan; kasir mendapat yang paling besar.'),
                Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
                TextInput::make('priority')->label('Prioritas')->integer()->minValue(-100)->maxValue(100)->default(0)->hidden(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('brand'))
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable()
                    ->description(fn (Promotion $record) => $record->code ? 'Kode '.$record->code : 'Otomatis'),
                TextColumn::make('type')->label('Jenis')->formatStateUsing(fn (string $state) => self::TYPES[$state] ?? $state),
                TextColumn::make('value')->label('Nilai')->alignEnd()
                    ->state(fn (Promotion $record) => match ($record->type) {
                        'percent' => MenuFields::plain((string) $record->value).'%',
                        'buy_x_get_y' => "Beli {$record->buy_qty} gratis {$record->get_qty}",
                        default => MenuFields::rupiah((string) $record->value),
                    }),
                TextColumn::make('brand.name')->label('Brand')->placeholder('Semua brand'),
                TextColumn::make('period')->label('Periode')
                    ->state(fn (Promotion $record) => $record->starts_at->setTimezone('Asia/Jakarta')->translatedFormat('d M Y')
                        .' – '.($record->ends_at?->setTimezone('Asia/Jakarta')->translatedFormat('d M Y') ?? 'seterusnya')),
                TextColumn::make('usage')->label('Terpakai')->alignEnd()
                    ->state(fn (Promotion $record) => $record->quota ? "{$record->used_count}/{$record->quota}" : (string) $record->used_count),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Promotion $record) => self::statusLabel($record))
                    ->color(fn (string $state) => match ($state) {
                        'Berjalan' => 'success', 'Terjadwal' => 'info', default => 'gray',
                    }),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                SelectFilter::make('type')->label('Jenis')->options(self::TYPES),
                TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([
                EditAction::make()->label('Ubah'),
                DeleteAction::make()->label('Hapus')
                    ->modalHeading('Hapus promo?')
                    ->using(function (Promotion $record): bool {
                        MenuFields::run(fn () => app(CatalogRemover::class)->promotion($record));

                        return true;
                    }),
            ])
            ->emptyStateHeading('Belum ada promo')
            ->emptyStateDescription('Buat promo seperti happy hour, potongan minimal belanja, atau beli 2 gratis 1.');
    }

    public static function statusLabel(Promotion $promo): string
    {
        $now = CarbonImmutable::now();

        return match (true) {
            ! $promo->is_active => 'Nonaktif',
            $promo->ends_at !== null && $promo->ends_at->lessThanOrEqualTo($now) => 'Berakhir',
            $promo->quota !== null && $promo->used_count >= $promo->quota => 'Kuota habis',
            $promo->starts_at->greaterThan($now) => 'Terjadwal',
            default => 'Berjalan',
        };
    }

    /**
     * Query dari Filament (tenant aktif) dipersempit ke brand yang boleh dilihat user.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        $user = MenuFields::user();
        $brands = $user === null ? [] : app(MenuScope::class)->brandIds($user);
        $query = parent::getEloquentQuery();

        return $brands === null ? $query : $query->where(fn (Builder $q) => $q->whereIn('brand_id', $brands)->orWhereNull('brand_id'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillData(Promotion $promo, array $data): array
    {
        $promo->load(['targets', 'outlets']);
        $data['item_ids'] = $promo->targets->where('target_type', 'item')->pluck('target_id')->values()->all();
        $data['category_ids'] = $promo->targets->where('target_type', 'category')->pluck('target_id')->values()->all();
        $data['outlet_ids'] = $promo->outlets->pluck('id')->all();
        foreach (['min_purchase', 'max_discount'] as $k) {
            $data[$k] = $data[$k] === null ? null : MenuFields::plain((string) $data[$k]);
        }

        return $data;
    }

    /**
     * Normalisasi & validasi aturan lintas field sebelum disimpan.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareData(array $data, ?Promotion $record = null): array
    {
        $data['scope'] = in_array($data['type'] ?? null, ['buy_x_get_y', 'special_price'], true) ? 'items' : ($data['scope'] ?? 'order');
        if (($data['type'] ?? null) === 'buy_x_get_y') {
            $data['value'] = '0';
        } else {
            $data['buy_qty'] = null;
            $data['get_qty'] = null;
        }
        if ($data['scope'] === 'order') {
            $data['item_ids'] = [];
            $data['category_ids'] = [];
        }
        foreach (['channel_codes', 'payment_methods', 'days_of_week'] as $k) {
            $data[$k] = empty($data[$k]) ? null : array_values($data[$k]);
        }
        if ($data['days_of_week'] !== null) {
            $data['days_of_week'] = array_map('intval', $data['days_of_week']);
        }
        foreach (['time_start', 'time_end'] as $k) {
            $data[$k] = empty($data[$k]) ? null : substr((string) $data[$k], 0, 5);
        }
        $data['item_ids'] = array_values($data['item_ids'] ?? []);
        $data['category_ids'] = array_values($data['category_ids'] ?? []);
        $data['outlet_ids'] = array_values($data['outlet_ids'] ?? []);

        $errors = PromotionRules::errors($data + ['code' => $record?->code], count($data['item_ids']) + count($data['category_ids']));
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromotions::route('/'),
            'create' => Pages\CreatePromotion::route('/create'),
            'edit' => Pages\EditPromotion::route('/{record}/edit'),
        ];
    }
}
