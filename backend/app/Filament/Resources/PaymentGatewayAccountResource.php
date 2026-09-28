<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentGatewayAccountResource\Pages;
use App\Filament\Support\MenuFields;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Payment\Application\Gateways\AinoGateway;
use App\Modules\Payment\Domain\Models\PaymentGatewayAccount;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Kredensial merchant payment gateway per outlet (keputusan user 27 Sep 2026).
 *
 * Dibatasi izin **`company.manage`** — bukan `outlet.manage` seperti daftar metode pembayaran.
 * Alasannya: yang diatur di sini adalah rekening mana yang menerima uang pelanggan. Manajer
 * outlet boleh menyalakan/mematikan QRIS dan mengatur MDR, tetapi tidak boleh mengganti
 * merchant tujuan dananya.
 *
 * `secret_key` tidak pernah dikirim balik ke layar. Kolomnya selalu kosong saat form dibuka;
 * mengosongkannya berarti "biarkan yang lama", mengisinya berarti mengganti.
 */
class PaymentGatewayAccountResource extends Resource
{
    protected static ?string $model = PaymentGatewayAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Keamanan';

    protected static ?string $modelLabel = 'kredensial payment gateway';

    protected static ?string $pluralModelLabel = 'Payment Gateway';

    protected static ?string $slug = 'payment-gateway';

    protected static ?int $navigationSort = 20;

    /** Daftar provider yang punya driver. Sandbox internal tidak butuh kredensial merchant. */
    public const PROVIDERS = [AinoGateway::PROVIDER => 'AINO (QRIS MPM)'];

    public static function canAccess(): bool
    {
        return MenuFields::user()?->can('company.manage') === true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('provider')->label('Gateway')->options(self::PROVIDERS)
                ->default(AinoGateway::PROVIDER)->required()
                ->disabledOn('edit'),

            Select::make('outlet_id')->label('Berlaku untuk')
                ->relationship('outlet', 'name')
                ->placeholder('Semua outlet company ini')
                ->helperText('Kosongkan bila satu merchant dipakai seluruh outlet. Baris khusus outlet mengalahkan baris ini.')
                ->searchable()->preload()
                ->options(fn (): array => Outlet::query()->orderBy('name')->pluck('name', 'id')->all()),

            Select::make('environment')->label('Lingkungan')
                ->options([
                    PaymentGatewayAccount::SANDBOX => 'Sandbox (pengembangan)',
                    PaymentGatewayAccount::PRODUCTION => 'Produksi',
                ])
                ->default(PaymentGatewayAccount::SANDBOX)->required(),

            TextInput::make('merchant_code')->label('Merchant code')->required()->maxLength(100)
                // Spasi ikut tertempel dari email/chat adalah penyebab klasik "merchant tidak dikenal".
                ->dehydrateStateUsing(fn (?string $state): string => trim((string) $state)),

            TextInput::make('secret_key')->label('Secret key')
                ->password()->revealable(false)->maxLength(255)
                ->required(fn (?PaymentGatewayAccount $record): bool => $record === null)
                // Kunci lama tidak pernah dikirim ke peramban; kosong berarti tidak diubah.
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->dehydrateStateUsing(fn (?string $state): string => trim((string) $state))
                ->helperText(fn (?PaymentGatewayAccount $record): string => $record === null
                    ? 'Disimpan terenkripsi dan tidak bisa dibaca lagi setelah disimpan.'
                    : 'Biarkan kosong bila tidak ingin mengganti kunci yang tersimpan.'),

            Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),

            Placeholder::make('peringatan')
                ->label('Perhatian')
                ->content('Sandbox AINO memakai rel QRIS sungguhan — transaksi uji memindahkan uang nyata. '
                    .'Pakai nominal sekecil mungkin saat mencoba.')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')->label('Gateway')
                    ->formatStateUsing(fn (string $state): string => self::PROVIDERS[$state] ?? $state),
                TextColumn::make('outlet.name')->label('Outlet')->placeholder('Semua outlet')->searchable(),
                TextColumn::make('environment')->label('Lingkungan')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === PaymentGatewayAccount::PRODUCTION ? 'Produksi' : 'Sandbox')
                    ->color(fn (string $state): string => $state === PaymentGatewayAccount::PRODUCTION ? 'success' : 'warning'),
                TextColumn::make('merchant_code')->label('Merchant code')->searchable(),
                TextColumn::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('updated_at')->label('Diubah')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                EditAction::make()->label('Ubah')->after(fn (Model $record) => self::audit('payment.gateway_account_updated', $record)),
                DeleteAction::make()->label('Hapus')->before(fn (Model $record) => self::audit('payment.gateway_account_deleted', $record)),
            ])
            ->emptyStateHeading('Belum ada kredensial gateway')
            ->emptyStateDescription('Tanpa kredensial, pembayaran QRIS di outlet akan ditolak dengan pesan untuk menghubungi admin.');
    }

    /** Perubahan kredensial adalah aksi sensitif; nilainya sendiri tidak pernah ikut dicatat. */
    public static function audit(string $action, Model $record): void
    {
        if (! $record instanceof PaymentGatewayAccount) {
            return;
        }

        app(AuditLogger::class)->log($action, $record, new: [
            'provider' => $record->provider,
            'outlet_id' => $record->outlet_id,
            'environment' => $record->environment,
            'merchant_code' => $record->merchant_code,
            'is_active' => $record->is_active,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentGatewayAccounts::route('/'),
            'create' => Pages\CreatePaymentGatewayAccount::route('/create'),
            'edit' => Pages\EditPaymentGatewayAccount::route('/{record}/edit'),
        ];
    }
}
