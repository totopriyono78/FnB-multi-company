<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CashAccountResource\Pages;
use App\Filament\Support\MenuFields;
use App\Filament\Support\TreasuryAccess;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Tenancy\Domain\Models\Outlet;
use App\Modules\Treasury\Application\CashAccountService;
use App\Modules\Treasury\Domain\Models\CashAccount;
use App\Modules\Treasury\Domain\Models\CashTransaction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rekening kas & bank (CSH-01).
 *
 * Kolom saldo dihitung dari buku besar tiap kali layar dibuka, bukan disimpan. Itu membuat layar ini
 * sedikit lebih lambat daripada membaca kolom — dan membuatnya mustahil menampilkan angka yang
 * berbeda dari jurnalnya, yang jauh lebih berharga.
 */
class CashAccountResource extends Resource
{
    protected static ?string $model = CashAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Kas & Hutang';

    protected static ?string $modelLabel = 'rekening kas/bank';

    protected static ?string $pluralModelLabel = 'Rekening Kas & Bank';

    protected static ?string $slug = 'kas/rekening';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return TreasuryAccess::canView();
    }

    public static function canCreate(): bool
    {
        return TreasuryAccess::canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return TreasuryAccess::canManage();
    }

    public static function canDelete(Model $record): bool
    {
        // Rekening tidak dihapus, hanya dinonaktifkan: mutasinya tetap ada di buku besar selamanya,
        // dan rekening yang hilang membuat mutasi itu tidak punya nama lagi.
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->label('Nama rekening')->required()->minLength(3)->maxLength(100),
            TextInput::make('code')->label('Kode')->maxLength(20)
                ->helperText('Singkatan untuk dipilih cepat. Kosongkan untuk dibuatkan dari namanya.')
                ->disabledOn('edit'),
            Select::make('kind')->label('Jenis')->required()->live()
                ->options(CashAccount::KIND_LABEL)->default(CashAccount::BANK)
                ->disabledOn('edit'),
            Select::make('outlet_id')->label('Milik outlet')->searchable()->placeholder('Entitas (kantor pusat)')
                ->options(fn () => Outlet::query()->orderBy('name')->pluck('name', 'id')->all())
                ->helperText('Diisi untuk kas kecil cabang. Mutasinya ikut terhitung di laba rugi outlet itu.'),
            TextInput::make('bank_name')->label('Nama bank')->maxLength(100)
                ->visible(fn (Get $get) => $get('kind') === CashAccount::BANK),
            TextInput::make('account_number')->label('Nomor rekening')->maxLength(50)
                ->visible(fn (Get $get) => $get('kind') === CashAccount::BANK),
            TextInput::make('account_holder')->label('Atas nama')->maxLength(100)
                ->visible(fn (Get $get) => $get('kind') === CashAccount::BANK),
            /*
             * Akun buku besar hanya bisa dipilih saat rekening dibuat, dan tidak pernah bisa diganti:
             * memindahkannya akan meninggalkan mutasi lama di akun lama sementara laporan membaca
             * akun baru — rekening yang saldonya benar hanya mulai tanggal pindah.
             */
            Select::make('account_id')->label('Akun buku besar')->searchable()
                ->placeholder('Buatkan akun baru di bawah Kas & Setara Kas')
                ->helperText('Satu akun hanya untuk satu rekening, supaya saldo tiap rekening tetap bisa dihitung sendiri.')
                ->hiddenOn('edit')
                /*
                 * Akun yang sudah dipakai rekening lain tidak ditawarkan. Disaring lewat subkueri,
                 * bukan relasi di model Account: modul Akuntansi tidak boleh tahu apa-apa tentang
                 * modul Kas — ketergantungan itu hanya boleh satu arah.
                 */
                ->options(fn () => Account::query()->where('is_postable', true)->where('is_active', true)
                    ->whereNotIn('id', CashAccount::query()->select('account_id'))
                    ->orderBy('code')->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all()),
            Placeholder::make('akun_terpasang')->label('Akun buku besar')->hiddenOn('create')
                ->content(fn (?CashAccount $record) => $record?->account?->label() ?? '-'),
            Toggle::make('is_active')->label('Aktif')->default(true)->hiddenOn('create')
                ->helperText('Rekening nonaktif tidak bisa dipakai mencatat mutasi baru, tetapi saldonya tetap tampil.'),
            Textarea::make('notes')->label('Catatan')->rows(2)->maxLength(300)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['account:id,code,name', 'outlet:id,name']))
            ->columns([
                TextColumn::make('code')->label('Kode')->fontFamily('mono')->searchable()->sortable(),
                TextColumn::make('name')->label('Rekening')->searchable()->wrap()
                    ->description(fn (CashAccount $record) => $record->kind === CashAccount::BANK
                        ? trim(($record->bank_name ?? '').' '.($record->account_number ?? '')) : null),
                TextColumn::make('kind')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state) => CashAccount::KIND_LABEL[$state] ?? $state)
                    ->color(fn (string $state) => $state === CashAccount::BANK ? 'info' : 'gray'),
                TextColumn::make('outlet.name')->label('Pemilik')->placeholder('Entitas'),
                TextColumn::make('account.code')->label('Akun')->fontFamily('mono')
                    ->description(fn (CashAccount $record) => $record->account?->name),
                TextColumn::make('saldo')->label('Saldo buku')->alignEnd()
                    ->state(fn (CashAccount $record) => (string) app(CashAccountService::class)->balance($record)->toScale(2))
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('code')
            ->actions([EditAction::make()->label('Ubah')])
            ->emptyStateHeading('Belum ada rekening kas/bank')
            ->emptyStateDescription('Daftarkan laci kasir, kas kecil cabang, dan tiap rekening bank. '
                .'Satu rekening = satu akun buku besar, supaya saldonya bisa dihitung sendiri-sendiri.');
    }

    /** Jumlah mutasi rekening ini — dipakai layar lain untuk memperingatkan sebelum menonaktifkan. */
    public static function transactionCount(CashAccount $cashAccount): int
    {
        return CashTransaction::query()
            ->where('cash_account_id', $cashAccount->id)
            ->orWhere('counter_cash_account_id', $cashAccount->id)
            ->count();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashAccounts::route('/'),
            'create' => Pages\CreateCashAccount::route('/baru'),
            'edit' => Pages\EditCashAccount::route('/{record}/ubah'),
        ];
    }
}
