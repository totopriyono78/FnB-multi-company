<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeviceResource\Pages;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\DevicePairingService;
use App\Modules\Tenancy\Domain\DeviceStatus;
use App\Modules\Tenancy\Domain\DeviceType;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/** FR-DEV-01, FR-DEV-02, FR-DEV-07 */
class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static ?string $navigationIcon = 'heroicon-o-device-tablet';

    protected static ?string $navigationGroup = 'Organisasi';

    protected static ?string $modelLabel = 'perangkat';

    protected static ?string $pluralModelLabel = 'Perangkat';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('outlet_id')->label('Outlet')
                ->relationship('outlet', 'name', fn (Builder $query) => self::scopeOutlets($query->where('is_active', true)))
                ->required()->preload()
                ->disabledOn('edit'),
            Select::make('type')->label('Jenis')
                ->options(collect(DeviceType::cases())->mapWithKeys(fn (DeviceType $t) => [$t->value => $t->label()]))
                ->default(DeviceType::Pos->value)->required()
                ->disabledOn('edit'),
            TextInput::make('code')->label('Kode perangkat')->required()->maxLength(10)
                ->regex('/^[A-Z0-9]+$/')
                ->mutateStateForValidationUsing(fn (?string $state) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $state) ?? ''))
                ->extraInputAttributes(['class' => 'uppercase'])->placeholder('POS01')
                ->helperText('Dipakai di nomor struk.')
                ->dehydrateStateUsing(fn (?string $state) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $state) ?? ''))
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, callable $get) => $rule
                    ->where('company_id', filament()->getTenant()?->getKey())
                    ->where('outlet_id', $get('outlet_id'))),
            TextInput::make('name')->label('Nama')->required()->maxLength(60)->placeholder('Kasir depan'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('outlet'))
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('outlet.name')->label('Outlet')->sortable(),
                TextColumn::make('type')->label('Jenis')->formatStateUsing(fn (DeviceType $state) => $state->label())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (DeviceStatus $state) => $state->label())
                    ->color(fn (DeviceStatus $state) => match ($state) {
                        DeviceStatus::Active => 'success',
                        DeviceStatus::Pending => 'warning',
                        DeviceStatus::Revoked => 'gray',
                    }),
                TextColumn::make('last_seen_at')->label('Koneksi')
                    ->formatStateUsing(fn (Device $record) => $record->isOnline() ? 'Online' : 'Offline · '.$record->last_seen_at?->diffForHumans())
                    ->placeholder('Belum pernah terhubung')
                    ->icon(fn (Device $record) => $record->isOnline() ? 'heroicon-m-signal' : 'heroicon-m-signal-slash')
                    ->color(fn (Device $record) => $record->isOnline() ? 'success' : 'danger'),
                TextColumn::make('pending_sync_count')->label('Belum sinkron')->numeric()->alignEnd(),
                TextColumn::make('app_version')->label('Versi')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('outlet_id')->label('Outlet')->relationship('outlet', 'name')->preload(),
                SelectFilter::make('status')->label('Status')->options(collect(DeviceStatus::cases())->mapWithKeys(fn (DeviceStatus $s) => [$s->value => $s->label()])),
            ])
            ->actions([
                EditAction::make()->label('Ubah'),
                ActionGroup::make([
                    Action::make('pairing')
                        ->label('Buat Kode Pairing')
                        ->icon('heroicon-o-qr-code')
                        ->visible(fn (Device $record) => $record->status !== DeviceStatus::Revoked && auth()->user()?->can('update', $record))
                        ->action(function (Device $record): void {
                            $pairing = app(DevicePairingService::class)->issueCode($record);
                            Notification::make()
                                ->success()
                                ->title('Kode pairing: '.chunk_split($pairing['code'], 4, ' '))
                                ->body('Masukkan kode ini di aplikasi kasir. Berlaku sampai '.$pairing['expires_at']->timezone(config('app.display_timezone'))->format('H.i').'.')
                                ->persistent()
                                ->send();
                        }),
                    Action::make('revoke')
                        ->label('Nonaktifkan')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->visible(fn (Device $record) => $record->status !== DeviceStatus::Revoked && auth()->user()?->can('update', $record))
                        ->requiresConfirmation()
                        ->modalHeading('Nonaktifkan perangkat?')
                        ->modalDescription('Perangkat tidak bisa dipakai lagi dan data lokalnya dihapus saat terhubung ke internet.')
                        ->form([Textarea::make('reason')->label('Alasan')->required()->maxLength(200)->placeholder('mis. Tablet hilang')])
                        ->modalSubmitActionLabel('Nonaktifkan')
                        ->action(function (Device $record, array $data): void {
                            app(DevicePairingService::class)->revoke($record, $data['reason']);
                            Notification::make()->success()->title('Perangkat dinonaktifkan')->send();
                        }),
                ])->label('Aksi lainnya')->tooltip('Aksi lainnya')->extraAttributes(['aria-label' => 'Aksi lainnya']),
            ])
            ->emptyStateHeading('Belum ada perangkat')
            ->emptyStateDescription('Daftarkan tablet kasir, lalu masukkan kode pairing di aplikasi.');
    }

    /**
     * @param  Builder<Outlet>  $query
     * @return Builder<Outlet>
     */
    private static function scopeOutlets(Builder $query): Builder
    {
        $user = auth()->user();

        return $user instanceof User ? app(AccessScope::class)->applyToOutletQuery($query, $user) : $query->whereRaw('1 = 0');
    }

    /**
     * Batasi daftar sesuai outlet yang boleh diakses user (FR-AUTH-06).
     *
     * @return Builder<Device>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        $scope = app(AccessScope::class);
        if ($scope->isCompanyWide($user)) {
            return $query;
        }

        return $query->whereHas('outlet', fn (Builder $outlet) => $scope->applyToOutletQuery($outlet, $user, 'outlets.id', 'outlets.brand_id'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDevices::route('/'),
            'create' => Pages\CreateDevice::route('/create'),
            'edit' => Pages\EditDevice::route('/{record}/edit'),
        ];
    }
}
