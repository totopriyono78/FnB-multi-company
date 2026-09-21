<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OutletResource\Pages;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/** FR-TEN-05, SRS §12.2 */
class OutletResource extends Resource
{
    protected static ?string $model = Outlet::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Organisasi';

    protected static ?string $modelLabel = 'outlet';

    protected static ?string $pluralModelLabel = 'Outlet';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        $tenantId = fn () => filament()->getTenant()?->getKey();

        return $form->schema([
            Tabs::make('Pengaturan outlet')->id('outlet')->columnSpanFull()->tabs([
                Tabs\Tab::make('Informasi')->schema([
                    Grid::make(3)->schema([
                        Select::make('brand_id')->label('Brand')
                            ->relationship('brand', 'name', fn (Builder $query) => self::scopeBrands($query->where('is_active', true)))
                            ->required()->preload()
                            ->exists(modifyRuleUsing: fn (Exists $rule) => $rule->where('company_id', $tenantId())),
                        TextInput::make('code')->label('Kode outlet')->required()->maxLength(10)
                            ->regex('/^[A-Z0-9]+$/')
                            ->mutateStateForValidationUsing(fn (?string $state) => strtoupper(trim((string) $state)))
                            ->extraInputAttributes(['class' => 'uppercase'])
                            ->helperText('Dipakai di nomor struk, mis. KMG')
                            ->dehydrateStateUsing(fn (?string $state) => strtoupper(trim((string) $state)))
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('company_id', $tenantId())),
                        TextInput::make('name')->label('Nama outlet')->required()->maxLength(120),
                    ]),
                    Textarea::make('address')->label('Alamat')->rows(2),
                    Grid::make(4)->schema([
                        TextInput::make('city')->label('Kota'),
                        TextInput::make('province')->label('Provinsi'),
                        TextInput::make('postal_code')->label('Kode pos')->regex('/^\d{5}$/'),
                        TextInput::make('phone')->label('Telepon')->tel(),
                        TextInput::make('latitude')->label('Latitude')->numeric()->minValue(-90)->maxValue(90),
                        TextInput::make('longitude')->label('Longitude')->numeric()->minValue(-180)->maxValue(180),
                        Select::make('timezone')->label('Zona waktu')->options([
                            'Asia/Jakarta' => 'WIB', 'Asia/Makassar' => 'WITA', 'Asia/Jayapura' => 'WIT',
                        ])->default('Asia/Jakarta')->required(),
                        TimePicker::make('business_day_cutoff')->label('Pergantian hari bisnis')
                            ->seconds(false)->default('04:00')
                            ->helperText('Transaksi sebelum jam ini masuk hari sebelumnya.'),
                    ]),
                    Toggle::make('is_active')->label('Outlet aktif')->default(true),
                ]),
                Tabs\Tab::make('Pajak & Harga')->schema([
                    Section::make('Pajak daerah')->description('Isi sesuai ketentuan Perda setempat. Nilai awal 0 sampai dikonfigurasi.')->columns(4)->schema([
                        TextInput::make('tax_name')->label('Nama pajak')->default('PB1')->required()->maxLength(20),
                        TextInput::make('tax_rate')->label('Tarif (%)')->numeric()->minValue(0)->maxValue(100)->default(0)->required(),
                        Toggle::make('tax_inclusive')->label('Harga sudah termasuk pajak')->inline(false),
                        Toggle::make('tax_on_service_charge')->label('Pajak dihitung dari subtotal + service charge')->default(true)->inline(false),
                        TextInput::make('npwpd')->label('NPWPD')->maxLength(30),
                    ]),
                    Section::make('Service charge & pembulatan')->columns(3)->schema([
                        TextInput::make('service_charge_rate')->label('Service charge (%)')->numeric()->minValue(0)->maxValue(100)->default(0)->required(),
                        Select::make('rounding_unit')->label('Pembulatan')->options([
                            0 => 'Tanpa pembulatan', 50 => 'Rp 50', 100 => 'Rp 100', 500 => 'Rp 500', 1000 => 'Rp 1.000',
                        ])->in([0, 50, 100, 500, 1000])->default(100)->required(),
                        Select::make('rounding_mode')->label('Arah pembulatan')->options([
                            'nearest' => 'Terdekat', 'up' => 'Ke atas', 'down' => 'Ke bawah',
                        ])->in(['nearest', 'up', 'down'])->default('nearest')->required(),
                    ]),
                ]),
                Tabs\Tab::make('Operasional')->schema([
                    Grid::make(3)->schema([
                        Select::make('order_mode')->label('Mode order')->options([
                            'quick_service' => 'Quick service (bayar dulu)',
                            'dine_in' => 'Dine-in (bayar di akhir)',
                        ])->default('quick_service')->required(),
                        Select::make('stock_deduction_trigger')->label('Stok berkurang saat')->options([
                            'on_payment' => 'Order dibayar',
                            'on_kitchen' => 'Order dikirim ke dapur',
                        ])->default('on_payment')->required(),
                        Toggle::make('allow_negative_stock')->label('Izinkan stok minus')->default(true)->inline(false),
                    ]),
                    Section::make('Struk')->columns(2)->schema([
                        TextInput::make('receipt_settings.header')->label('Teks atas struk')->maxLength(200),
                        TextInput::make('receipt_settings.footer')->label('Teks bawah struk')->maxLength(200)->placeholder('Terima kasih, sampai jumpa lagi'),
                        Select::make('receipt_settings.paper_width')->label('Lebar kertas')->options([58 => '58 mm', 80 => '80 mm'])->default(80),
                        Toggle::make('receipt_settings.show_logo')->label('Cetak logo')->inline(false),
                    ]),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('brand'))
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('brand.name')->label('Brand')->sortable(),
                TextColumn::make('city')->label('Kota')->searchable()->toggleable(),
                TextColumn::make('tax_rate')->label('Pajak')->suffix('%')->alignEnd(),
                TextColumn::make('service_charge_rate')->label('Service')->suffix('%')->alignEnd()->toggleable(),
                TextColumn::make('order_mode')->label('Mode')->formatStateUsing(fn (string $state) => $state === 'dine_in' ? 'Dine-in' : 'Quick service')->toggleable(),
                TextColumn::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('brand_id')->label('Brand')->relationship('brand', 'name')->preload(),
                TernaryFilter::make('is_active')->label('Status aktif'),
            ])
            ->actions([EditAction::make()->label('Ubah')])
            ->emptyStateHeading('Belum ada outlet')
            ->emptyStateDescription('Tambahkan outlet agar perangkat kasir bisa didaftarkan.');
    }

    /**
     * Brand yang boleh dipilih: seluruh brand bagi user tingkat company, atau brand yang dipegang.
     *
     * @param  Builder<Brand>  $query
     * @return Builder<Brand>
     */
    private static function scopeBrands(Builder $query): Builder
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        $scope = app(AccessScope::class)->for($user);

        return $scope === null ? $query : $query->whereIn('id', $scope['brands']);
    }

    /**
     * Batasi daftar sesuai cakupan brand/outlet user (FR-AUTH-06).
     *
     * @return Builder<Outlet>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        return $user instanceof User ? app(AccessScope::class)->applyToOutletQuery($query, $user) : $query->whereRaw('1 = 0');
    }

    public static function getRelations(): array
    {
        return [OutletResource\RelationManagers\PaymentMethodsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOutlets::route('/'),
            'create' => Pages\CreateOutlet::route('/create'),
            'edit' => Pages\EditOutlet::route('/{record}/edit'),
        ];
    }
}
