<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffResource\Pages;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Application\StaffManager;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\RoleScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Kelola staf, role, cakupan, dan PIN (FR-AUTH-03, FR-AUTH-05, FR-AUTH-06, FR-AUTH-09). */
class StaffResource extends Resource
{
    protected static ?string $model = CompanyUser::class;

    protected static ?string $tenantRelationshipName = 'companyUsers';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Pengguna & Akses';

    protected static ?string $modelLabel = 'staf';

    protected static ?string $pluralModelLabel = 'Staf';

    protected static ?string $slug = 'staf';

    /** @return array<string, string> */
    public static function roleOptions(): array
    {
        $actor = auth()->user();
        $query = Role::query()->where('company_id', filament()->getTenant()?->getKey())->orderBy('label');

        if ($actor !== null && ! $actor->can('user.manage')) {
            $query->whereIn('name', StaffManager::OUTLET_ASSIGNABLE_ROLES);
        }

        return $query->pluck('label', 'name')->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Data staf')->columns(2)->schema([
                TextInput::make('name')->label('Nama lengkap')->required()->maxLength(100),
                TextInput::make('email')->label('Email')->email()->required()->maxLength(255)
                    ->disabledOn('edit')
                    ->helperText('Staf baru menerima email untuk membuat password.'),
                TextInput::make('phone')->label('Nomor HP')->tel()->placeholder('0812 3456 7890')->disabledOn('edit'),
                TextInput::make('employee_code')->label('Kode karyawan')->maxLength(20),
                Toggle::make('is_active')->label('Aktif')->default(true)->hiddenOn('create'),
            ]),
            Section::make('Hak akses')->columns(2)->schema([
                CheckboxList::make('roles')->label('Role')->required()->options(fn () => self::roleOptions())->columns(2),
                CheckboxList::make('scope_outlets')->label('Batasi ke outlet')
                    ->columns(2)
                    ->options(function () {
                        $query = Outlet::query()->orderBy('name');
                        $actor = auth()->user();
                        if ($actor instanceof User) {
                            app(AccessScope::class)->applyToOutletQuery($query, $actor);
                        }

                        return $query->pluck('name', 'id')->all();
                    })
                    ->required(fn () => ! (auth()->user()?->can('user.manage') ?? false))
                    ->helperText(fn () => (auth()->user()?->can('user.manage') ?? false)
                        ? 'Kosongkan bila staf boleh mengakses semua outlet.'
                        : 'Pilih outlet tempat staf bekerja.'),
                CheckboxList::make('scope_brands')->label('Batasi ke brand')
                    ->columns(2)
                    ->options(fn () => Brand::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn () => auth()->user()?->can('user.manage') ?? false),
                TextInput::make('pin')->label('PIN kasir')
                    ->password()->revealable()
                    ->regex('/^\d{4,6}$/')
                    ->validationMessages(['regex' => 'PIN harus 4–6 digit angka.'])
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Isi hanya bila ingin mengganti PIN.' : 'Wajib untuk kasir dan supervisor.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user.roles']))
            ->columns([
                TextColumn::make('user.name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('user.email')->label('Email')->searchable()->toggleable(),
                TextColumn::make('employee_code')->label('Kode')->placeholder('-'),
                TextColumn::make('roles')->label('Role')->badge()
                    ->getStateUsing(fn (CompanyUser $record) => $record->user->roles->pluck('label')->all()),
                TextColumn::make('pin_hash')->label('PIN')
                    ->getStateUsing(fn (CompanyUser $record) => $record->hasPin() ? 'Sudah diatur' : 'Belum ada'),
                TextColumn::make('pin_locked_until')->label('Status PIN')
                    ->getStateUsing(fn (CompanyUser $record) => $record->isPinLocked() ? 'Terkunci' : null)
                    ->badge()->color('danger')->placeholder('-'),
                TextColumn::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->filters([TernaryFilter::make('is_active')->label('Status aktif')])
            ->actions([
                EditAction::make()->label('Ubah'),
                ActionGroup::make([
                    Action::make('unlock')
                        ->label('Buka Kunci PIN')
                        ->icon('heroicon-o-lock-open')
                        ->visible(fn (CompanyUser $record) => $record->isPinLocked() && (auth()->user()?->can('update', $record) ?? false))
                        ->action(function (CompanyUser $record): void {
                            abort_unless(auth()->user()?->can('update', $record), 403);
                            app(StaffManager::class)->guardTarget(auth()->user(), $record);
                            $record->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null])->save();
                            Notification::make()->success()->title('PIN dibuka kembali')->send();
                        }),
                    Action::make('revoke')
                        ->label('Keluarkan dari Semua Sesi')
                        ->icon('heroicon-o-arrow-right-start-on-rectangle')
                        ->color('danger')
                        ->visible(fn (CompanyUser $record) => auth()->user()?->can('update', $record) ?? false)
                        ->requiresConfirmation()
                        ->modalDescription('Staf harus login ulang di semua perangkat.')
                        ->modalSubmitActionLabel('Keluarkan')
                        ->action(function (CompanyUser $record): void {
                            abort_unless(auth()->user()?->can('update', $record), 403);
                            $manager = app(StaffManager::class);
                            $manager->guardTarget(auth()->user(), $record);
                            $count = $manager->revokeSessions($record);
                            Notification::make()->success()->title("{$count} sesi diakhiri")->send();
                        }),
                ])->label('Aksi lainnya')->tooltip('Aksi lainnya')->extraAttributes(['aria-label' => 'Aksi lainnya']),
            ])
            ->emptyStateHeading('Belum ada staf')
            ->emptyStateDescription('Tambahkan kasir dan manajer outlet beserta PIN-nya.');
    }

    /** @return Builder<CompanyUser> */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $actor = auth()->user();
        $mine = $actor instanceof User
            ? app(AccessScope::class)->for($actor)
            : null;

        if ($mine !== null) {
            $query->whereHas('scopes', fn (Builder $s) => $s
                ->where('scope_type', RoleScope::OUTLET)
                ->whereIn('scope_id', $mine['outlets']));
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaff::route('/'),
            'create' => Pages\CreateStaff::route('/create'),
            'edit' => Pages\EditStaff::route('/{record}/edit'),
        ];
    }
}
