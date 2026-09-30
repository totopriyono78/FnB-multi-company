<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccountResource\Pages;
use App\Filament\Support\AccountingAccess;
use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Domain\Models\Account;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Bagan akun per entitas (ACC-01).
 *
 * Alamatnya diawali `pembukuan/`, bukan `akuntansi/`: halaman PROTOTIPE lama masih memakai
 * `akuntansi/bagan-akun` dan `akuntansi/jurnal`, dan dua rute dengan alamat sama membuat menu
 * seluruh panel meledak (RouteNotFoundException). Prototipe sengaja dibiarkan utuh sampai user
 * memutuskan untuk mempensiunkannya; sampai saat itu keduanya hidup berdampingan — yang sungguhan
 * di grup "Akuntansi", yang lama di grup "Akuntansi (Prototipe)".
 */
class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationGroup = 'Akuntansi';

    protected static ?string $modelLabel = 'akun';

    protected static ?string $pluralModelLabel = 'Bagan Akun';

    protected static ?string $slug = 'pembukuan/bagan-akun';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return AccountingAccess::canView();
    }

    public static function canCreate(): bool
    {
        return AccountingAccess::canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return AccountingAccess::canManage();
    }

    public static function canDelete(Model $record): bool
    {
        return AccountingAccess::canManage() && $record instanceof Account && ! $record->is_system;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('code')->label('Kode akun')->required()->maxLength(20)
                ->helperText('Empat digit, mengikuti kelompoknya: 1 Aset · 2 Liabilitas · 3 Ekuitas · 4 Pendapatan · 5 HPP · 6 Beban.')
                ->disabled(fn (?Account $record) => $record !== null && app(ChartOfAccounts::class)->hasEntries($record)),
            TextInput::make('name')->label('Nama akun')->required()->maxLength(120),
            Select::make('type')->label('Jenis')->required()->options(Account::TYPE_LABEL)
                ->disabled(fn (?Account $record) => $record !== null && app(ChartOfAccounts::class)->hasEntries($record))
                ->helperText(fn (?Account $record) => $record !== null && app(ChartOfAccounts::class)->hasEntries($record)
                    ? 'Tidak dapat diubah: akun ini sudah punya jurnal, dan mengubah jenisnya akan mengubah cara seluruh riwayatnya dibaca.'
                    : null),
            Select::make('parent_id')->label('Induk')->searchable()->placeholder('Tanpa induk')
                ->options(fn (?Account $record) => Account::query()
                    ->when($record !== null, fn ($q) => $q->whereKeyNot($record->id))
                    ->orderBy('code')->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all()),
            Toggle::make('is_postable')->label('Bisa dijurnal')
                ->helperText('Matikan untuk akun induk yang hanya dipakai menjumlah di laporan.')->default(true),
            Toggle::make('is_active')->label('Aktif')->default(true),
            Textarea::make('description')->label('Keterangan')->maxLength(300)->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Kode')->fontFamily('mono')->sortable()->searchable(),
                TextColumn::make('name')->label('Nama akun')->searchable()
                    ->description(fn (Account $record) => $record->parent?->label()),
                TextColumn::make('type')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state) => Account::TYPE_LABEL[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Account::ASSET => 'info',
                        Account::LIABILITY, Account::EQUITY => 'warning',
                        Account::REVENUE => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('normal_balance')->label('Saldo normal')
                    ->formatStateUsing(fn (string $state) => $state === 'debit' ? 'Debit' : 'Kredit'),
                IconColumn::make('is_postable')->label('Bisa dijurnal')->boolean(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
                IconColumn::make('is_system')->label('Bawaan')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('type')->label('Jenis')->options(Account::TYPE_LABEL),
                TernaryFilter::make('is_active')->label('Aktif')->default(true),
                TernaryFilter::make('is_postable')->label('Bisa dijurnal'),
            ])
            ->emptyStateHeading('Bagan akun masih kosong')
            ->emptyStateDescription('Pasang template standar F&B Indonesia lewat tombol di kanan atas, lalu sesuaikan seperlunya.');
    }

    /** Dipakai halaman daftar & tombol di layar kosong. */
    public static function installTemplate(): void
    {
        if (! AccountingAccess::canManage()) {
            Notification::make()->danger()->title('Tidak berwenang')->send();

            return;
        }
        try {
            $created = app(ChartOfAccounts::class)->installTemplate();
        } catch (AccountingException $e) {
            Notification::make()->danger()->title('Gagal memasang template')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()
            ->title($created === 0 ? 'Bagan akun sudah lengkap' : "{$created} akun ditambahkan")
            ->body($created === 0 ? 'Semua akun template sudah ada; tidak ada yang diubah.' : null)
            ->send();
    }

    /** @return Builder<Account> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('parent');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccounts::route('/'),
            'create' => Pages\CreateAccount::route('/baru'),
            'edit' => Pages\EditAccount::route('/{record}/ubah'),
        ];
    }
}
