<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CashTransactionResource\Pages;
use App\Filament\Support\MenuFields;
use App\Filament\Support\TreasuryAccess;
use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Tenancy\Domain\Models\Outlet;
use App\Modules\Treasury\Application\TreasuryException;
use App\Modules\Treasury\Domain\Models\CashAccount;
use App\Modules\Treasury\Domain\Models\CashTransaction;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Buku kas: kas masuk, kas keluar, transfer antar rekening (CSH-02, CSH-03).
 *
 * Layar ini **bukan** jalan untuk membayar pihak ketiga — itu tetap lewat SPPK → advis bayar, karena
 * di situlah batas wewenang berlaku. Yang masuk ke sini adalah mutasi yang tidak punya pihak ketiga
 * untuk disetujui: setoran hasil penjualan dari laci ke bank, pengisian kas kecil, penerimaan lain.
 *
 * Mutasi tidak bisa diubah atau dihapus setelah dicatat. Koreksinya lewat jurnal balik, sama seperti
 * jurnal lain — kas adalah perkara yang paling sering dipertanyakan belakangan, dan riwayat yang
 * bisa disunting bukan riwayat.
 */
class CashTransactionResource extends Resource
{
    protected static ?string $model = CashTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Kas & Hutang';

    protected static ?string $modelLabel = 'mutasi kas';

    protected static ?string $pluralModelLabel = 'Buku Kas';

    protected static ?string $slug = 'kas/mutasi';

    protected static ?int $navigationSort = 3;

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
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('kind')->label('Jenis')->required()->live()
                ->options(CashTransaction::KIND_LABEL)->default(CashTransaction::IN),
            DatePicker::make('transaction_date')->label('Tanggal')->required()
                ->default(fn () => CarbonImmutable::now(config('app.display_timezone'))->format('Y-m-d')),
            Select::make('cash_account_id')->label(fn (Get $get) => $get('kind') === CashTransaction::TRANSFER
                ? 'Dari rekening' : 'Rekening')
                ->required()->searchable()->live()
                ->options(fn () => self::cashOptions()),
            Select::make('counter_cash_account_id')->label('Ke rekening')->searchable()
                ->visible(fn (Get $get) => $get('kind') === CashTransaction::TRANSFER)
                ->required(fn (Get $get) => $get('kind') === CashTransaction::TRANSFER)
                // Rekening asal tidak ditawarkan sebagai tujuan: memindahkan uang ke tempat yang sama
                // adalah salah ketik, dan menawarkannya hanya mengundang salah ketik itu.
                ->options(fn (Get $get) => array_diff_key(self::cashOptions(), [(string) $get('cash_account_id') => null])),
            Select::make('contra_account_id')
                ->label(fn (Get $get) => $get('kind') === CashTransaction::OUT ? 'Dibebankan ke akun' : 'Diterima dari akun')
                ->required(fn (Get $get) => $get('kind') !== CashTransaction::TRANSFER)
                ->visible(fn (Get $get) => $get('kind') !== CashTransaction::TRANSFER)
                ->searchable()
                ->options(fn () => Account::query()->where('is_postable', true)->where('is_active', true)
                    ->orderBy('code')->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all()),
            TextInput::make('amount')->label('Nilai (Rp)')->numeric()->required()->minValue(1),
            Select::make('outlet_id')->label('Outlet')->searchable()->placeholder('Ikut rekening')
                ->options(fn () => Outlet::query()->orderBy('name')->pluck('name', 'id')->all())
                ->helperText('Kosongkan untuk mengikuti outlet pemilik rekening.'),
            TextInput::make('reference')->label('Nomor bukti')->maxLength(100),
            TextInput::make('description')->label('Keterangan')->required()->minLength(3)->maxLength(300)
                ->columnSpanFull(),
            /*
             * Dikatakan di depan, bukan ditemukan belakangan: jurnalnya lahir draft dan belum
             * mengubah saldo apa pun sampai diposting orang kedua. Tanpa kalimat ini, orang mencatat
             * setoran lalu bingung kenapa saldo di layar tidak berubah.
             */
            Placeholder::make('catatan_jurnal')->label('Yang akan terjadi')->columnSpanFull()
                ->content('Mutasi ini langsung membuat jurnalnya sebagai draft. Saldo rekening baru berubah '
                    .'setelah jurnal itu diajukan dan diposting oleh orang lain, seperti jurnal mana pun.'),
        ])->columns(2);
    }

    /** @return array<string, string> */
    private static function cashOptions(): array
    {
        return CashAccount::query()->where('is_active', true)->orderBy('kind')->orderBy('code')
            ->get()->mapWithKeys(fn (CashAccount $c) => [$c->id => $c->label()])->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'cashAccount:id,name,code,kind,bank_name,account_number',
                'counterCashAccount:id,name,code,kind,bank_name,account_number',
                'contraAccount:id,code,name', 'journal:id,number,status', 'outlet:id,name',
            ]))
            ->columns([
                TextColumn::make('transaction_date')->label('Tanggal')->date('d M Y')->sortable(),
                TextColumn::make('number')->label('Nomor')->fontFamily('mono')->searchable(),
                TextColumn::make('kind')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state) => CashTransaction::KIND_LABEL[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        CashTransaction::IN => 'success',
                        CashTransaction::OUT => 'danger',
                        default => 'info',
                    }),
                TextColumn::make('cashAccount.name')->label('Rekening')
                    ->description(fn (CashTransaction $record) => $record->kind === CashTransaction::TRANSFER
                        ? '→ '.($record->counterCashAccount?->name ?: '-')
                        : ($record->contraAccount?->label() ?: '-')),
                TextColumn::make('description')->label('Keterangan')->wrap()->searchable(),
                TextColumn::make('amount')->label('Nilai')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('journal.status')->label('Jurnal')->badge()->placeholder('-')
                    ->formatStateUsing(fn (?string $state) => Journal::STATUS_LABEL[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        Journal::POSTED => 'success',
                        Journal::SUBMITTED => 'info',
                        default => 'warning',
                    })
                    ->description(fn (CashTransaction $record) => $record->journal?->number),
                TextColumn::make('reference')->label('No. bukti')->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->filters([
                SelectFilter::make('kind')->label('Jenis')->options(CashTransaction::KIND_LABEL),
                SelectFilter::make('cash_account_id')->label('Rekening')->options(fn () => self::cashOptions()),
                Filter::make('periode')
                    ->form([
                        DatePicker::make('from')->label('Dari tanggal'),
                        DatePicker::make('until')->label('Sampai tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->where('transaction_date', '>=', $v))
                        ->when($data['until'] ?? null, fn ($q, $v) => $q->where('transaction_date', '<=', $v))),
                Filter::make('jurnal_belum_posting')->label('Jurnalnya belum diposting')
                    ->query(fn (Builder $query) => $query->whereHas('journal',
                        fn (Builder $q) => $q->where('status', '!=', Journal::POSTED))),
            ])
            ->actions([
                Action::make('jurnal')
                    ->label('Lihat jurnal')->icon('heroicon-o-book-open')->color('gray')
                    ->visible(fn (CashTransaction $record) => $record->journal_id !== null)
                    ->url(fn (CashTransaction $record) => JournalResource::getUrl('view', ['record' => $record->journal_id])),
            ])
            ->emptyStateHeading('Belum ada mutasi kas')
            ->emptyStateDescription('Catat setoran hasil penjualan, pengisian kas kecil, dan transfer antar rekening di sini. '
                .'Pembayaran ke pihak ketiga tetap lewat Pengajuan Pembayaran (SPPK).');
    }

    /** Galat aturan ditampilkan sebagai notifikasi, bukan layar 500. */
    public static function errorMessage(TreasuryException|AccountingException $e): string
    {
        return $e->getMessage();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashTransactions::route('/'),
            'create' => Pages\CreateCashTransaction::route('/baru'),
        ];
    }
}
