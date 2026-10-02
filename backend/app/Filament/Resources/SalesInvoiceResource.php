<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SalesInvoiceResource\Pages;
use App\Filament\Support\MenuFields;
use App\Filament\Support\TreasuryAccess;
use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Application\AttachmentStore;
use App\Modules\Shared\Application\DocumentAttachments;
use App\Modules\Shared\Domain\Models\DocumentAttachment;
use App\Modules\Tenancy\Domain\Models\Outlet;
use App\Modules\Treasury\Application\ReceivableService;
use App\Modules\Treasury\Application\TreasuryException;
use App\Modules\Treasury\Domain\Models\CashAccount;
use App\Modules\Treasury\Domain\Models\Customer;
use App\Modules\Treasury\Domain\Models\SalesInvoice;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Tagihan keluar — penjualan yang uangnya datang belakangan (AR-02).
 *
 * Bukan penjualan kasir: penjualan POS dibayar di tempat dan sudah punya jalurnya sendiri sampai ke
 * jurnal. Yang dicatat di sini adalah katering korporat, sewa tempat, kerja sama acara — penjualan
 * yang menjadi piutang, dan karena itu bisa terlupa.
 */
class SalesInvoiceResource extends Resource
{
    protected static ?string $model = SalesInvoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Kas & Hutang';

    protected static ?string $modelLabel = 'tagihan keluar';

    protected static ?string $pluralModelLabel = 'Tagihan Keluar';

    protected static ?string $slug = 'piutang/tagihan';

    protected static ?int $navigationSort = 8;

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
        return TreasuryAccess::canManage() && $record instanceof SalesInvoice && $record->isEditable();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canEdit($record);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('customer_id')->label('Pelanggan')->required()->searchable()
                ->options(fn () => Customer::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('outlet_id')->label('Outlet')->searchable()->placeholder('Entitas')
                ->options(fn () => Outlet::query()->orderBy('name')->pluck('name', 'id')->all()),
            DatePicker::make('invoice_date')->label('Tanggal tagihan')->required()
                ->default(fn () => CarbonImmutable::now(config('app.display_timezone'))->format('Y-m-d')),
            DatePicker::make('due_date')->label('Jatuh tempo')
                ->helperText('Kosongkan untuk mengikuti termin pelanggan.'),
            TextInput::make('description')->label('Keterangan')->required()->minLength(3)->maxLength(300)
                ->columnSpan(2),
            Repeater::make('lines')->label('Baris tagihan')->columnSpanFull()
                ->minItems(1)->defaultItems(1)->live(onBlur: true)
                ->addActionLabel('Tambah baris')
                ->schema([
                    TextInput::make('description')->label('Keterangan')->required()->maxLength(200)->columnSpan(4),
                    Select::make('account_id')->label('Akun pendapatan')->required()->searchable()->columnSpan(3)
                        // Hanya akun pendapatan: tagihan keluar yang dikreditkan ke akun beban akan
                        // membuat laba rugi terbaca terbalik, dan itu tidak pernah ketahuan dari nilainya.
                        ->options(fn () => Account::query()->where('is_postable', true)->where('is_active', true)
                            ->where('type', Account::REVENUE)
                            ->orderBy('code')->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all()),
                    TextInput::make('quantity')->label('Jumlah')->numeric()->default('1')->columnSpan(1),
                    TextInput::make('unit_price')->label('Harga satuan')->numeric()->default('0')->columnSpan(2)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => $set('amount',
                            (string) self::number($get('quantity'))->multipliedBy(self::number($get('unit_price')))->toScale(2))),
                    TextInput::make('amount')->label('Nilai')->numeric()->required()->columnSpan(2),
                ])->columns(12),
            Toggle::make('has_tax_invoice')->label('Terbitkan faktur pajak (PPN keluaran)')->live()->default(false),
            TextInput::make('tax_amount')->label('PPN keluaran (Rp)')->numeric()->default('0')->live(onBlur: true)
                ->visible(fn (Get $get) => $get('has_tax_invoice') === true),
            TextInput::make('tax_invoice_no')->label('No. faktur pajak')->maxLength(60)
                ->required(fn (Get $get) => $get('has_tax_invoice') === true)
                ->visible(fn (Get $get) => $get('has_tax_invoice') === true),
            DatePicker::make('tax_invoice_date')->label('Tanggal faktur pajak')
                ->visible(fn (Get $get) => $get('has_tax_invoice') === true),
            Placeholder::make('jumlah')->label('Jumlah tagihan')->columnSpanFull()
                ->content(function (Get $get): string {
                    $dpp = BigDecimal::zero();
                    foreach ((array) ($get('lines') ?? []) as $line) {
                        $dpp = $dpp->plus(self::number(is_array($line) ? ($line['amount'] ?? '0') : '0'));
                    }
                    $ppn = $get('has_tax_invoice') === true ? self::number($get('tax_amount')) : BigDecimal::zero();

                    return 'DPP '.MenuFields::rupiah((string) $dpp->toScale(2))
                        .' + PPN '.MenuFields::rupiah((string) $ppn->toScale(2))
                        .' = '.MenuFields::rupiah((string) $dpp->plus($ppn)->toScale(2));
                }),
        ])->columns(2);
    }

    private static function number(mixed $value): BigDecimal
    {
        $text = is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';

        return preg_match('/^\d+(\.\d+)?$/', $text) === 1 ? BigDecimal::of($text) : BigDecimal::zero();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'customer:id,name', 'outlet:id,name', 'journal:id,number,status',
            ]))
            ->columns([
                TextColumn::make('number')->label('No. tagihan')->fontFamily('mono')->searchable(),
                TextColumn::make('invoice_date')->label('Tanggal')->date('d M Y')->sortable(),
                TextColumn::make('customer.name')->label('Pelanggan')->searchable()->wrap(),
                TextColumn::make('description')->label('Keterangan')->wrap()->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total')->label('Nilai')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('due_date')->label('Jatuh tempo')->date('d M Y')->placeholder('-')->sortable()
                    ->description(fn (SalesInvoice $record) => $record->status === SalesInvoice::ISSUED
                        && $record->daysOverdue() > 0 ? 'telat '.$record->daysOverdue().' hari' : null)
                    ->color(fn (SalesInvoice $record) => $record->status === SalesInvoice::ISSUED
                        && $record->daysOverdue() > 0 ? 'danger' : null),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => SalesInvoice::STATUS_LABEL[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        SalesInvoice::PAID => 'success',
                        SalesInvoice::ISSUED => 'warning',
                        SalesInvoice::CANCELLED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('paid_amount')->label('Diterima')->alignEnd()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state))
                    ->description(fn (SalesInvoice $record) => $record->outstanding()->isPositive()
                        && $record->status === SalesInvoice::ISSUED
                        ? 'sisa '.MenuFields::rupiah((string) $record->outstanding()->toScale(2)) : null),
            ])
            ->defaultSort('invoice_date', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Status')->options(SalesInvoice::STATUS_LABEL),
                SelectFilter::make('customer_id')->label('Pelanggan')
                    ->options(fn () => Customer::query()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('jatuh_tempo')->label('Sudah lewat jatuh tempo')
                    ->query(fn (Builder $query) => $query->where('status', SalesInvoice::ISSUED)
                        ->whereNotNull('due_date')->whereDate('due_date', '<', now())),
            ])
            ->actions([
                ViewAction::make()->label('Detail'),
                EditAction::make()->label('Ubah'),
                Action::make('issue')
                    ->label('Terbitkan')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (SalesInvoice $record) => $record->isEditable() && TreasuryAccess::canManage())
                    ->requiresConfirmation()
                    ->modalHeading('Terbitkan tagihan')
                    ->modalDescription('Piutang muncul dan pendapatannya diakui sejak tanggal tagihan. '
                        .'Jurnalnya lahir sebagai draft dan tetap harus diposting orang lain.')
                    ->modalSubmitActionLabel('Terbitkan tagihan')
                    ->action(fn (SalesInvoice $record) => self::run(
                        fn (User $by) => app(ReceivableService::class)->issue($record, $by),
                        'Tagihan diterbitkan, piutangnya tercatat.')),
                Action::make('receive')
                    ->label('Catat pelunasan')->icon('heroicon-o-banknotes')->color('success')
                    ->visible(fn (SalesInvoice $record) => TreasuryAccess::canManage()
                        && in_array($record->status, [SalesInvoice::ISSUED, SalesInvoice::PAID], true)
                        && $record->outstanding()->isPositive())
                    ->modalHeading('Catat pelunasan dari pelanggan')
                    ->modalDescription('Jurnalnya lahir sebagai draft: Dr rekening penerima / Cr Piutang Usaha.')
                    ->modalSubmitActionLabel('Simpan pelunasan')
                    ->form([
                        DatePicker::make('received_on')->label('Tanggal terima')->required()
                            ->default(fn () => CarbonImmutable::now(config('app.display_timezone'))->format('Y-m-d')),
                        TextInput::make('amount')->label('Nilai diterima (Rp)')->numeric()->required()->minValue(1)
                            ->default(fn (SalesInvoice $record) => (string) $record->outstanding()->toScale(2))
                            ->helperText(fn (SalesInvoice $record) => 'Sisa tagihan: '
                                .MenuFields::rupiah((string) $record->outstanding()->toScale(2))),
                        Select::make('cash_account_id')->label('Masuk ke rekening')->required()->searchable()
                            ->options(fn () => CashAccount::query()->where('is_active', true)->orderBy('code')
                                ->get()->mapWithKeys(fn (CashAccount $c) => [$c->id => $c->label()])->all()),
                        TextInput::make('reference')->label('No. bukti transfer')->maxLength(100),
                    ])
                    ->action(fn (SalesInvoice $record, array $data) => self::run(
                        fn (User $by) => app(ReceivableService::class)->receive($record, [
                            'received_on' => (string) $data['received_on'],
                            'amount' => (string) $data['amount'],
                            'cash_account_id' => (string) $data['cash_account_id'],
                            'reference' => $data['reference'] ?? null,
                        ], $by),
                        'Pelunasan tercatat.')),
                Action::make('attach')
                    ->label('Lampiran')->icon('heroicon-o-paper-clip')->color('gray')
                    ->visible(fn () => TreasuryAccess::canManage())
                    ->modalHeading('Lampirkan dokumen')
                    ->modalSubmitActionLabel('Simpan lampiran')
                    ->form([
                        FileUpload::make('files')->label('Berkas')->multiple()->required()
                            ->acceptedFileTypes(AttachmentStore::MIMES)
                            ->maxSize(fn () => app(AttachmentStore::class)->maxKb())
                            ->storeFiles(false),
                    ])
                    ->action(fn (SalesInvoice $record, array $data) => self::run(function (User $by) use ($record, $data): void {
                        foreach ((array) ($data['files'] ?? []) as $file) {
                            if ($file instanceof UploadedFile) {
                                app(DocumentAttachments::class)->attach(DocumentAttachment::SALES_INVOICE, $record, $file, $by);
                            }
                        }
                    }, 'Lampiran tersimpan.')),
                Action::make('cancel')
                    ->label('Batalkan')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (SalesInvoice $record) => $record->isEditable() && TreasuryAccess::canManage())
                    ->modalHeading('Batalkan tagihan draft')
                    ->modalSubmitActionLabel('Batalkan tagihan')
                    ->form([TextInput::make('reason')->label('Alasan')->required()->minLength(3)->maxLength(300)])
                    ->action(fn (SalesInvoice $record, array $data) => self::run(
                        fn (User $by) => app(ReceivableService::class)->cancel($record, $by, (string) $data['reason']),
                        'Tagihan dibatalkan.')),
            ])
            ->emptyStateHeading('Belum ada tagihan keluar')
            ->emptyStateDescription('Catat penjualan yang dibayar belakangan di sini — katering korporat, sewa tempat, kerja sama acara.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('No. tagihan')->fontFamily('mono'),
                TextEntry::make('invoice_date')->label('Tanggal')->date('d M Y'),
                TextEntry::make('due_date')->label('Jatuh tempo')->date('d M Y')->placeholder('-'),
                TextEntry::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => SalesInvoice::STATUS_LABEL[$state] ?? $state),
                TextEntry::make('customer.name')->label('Pelanggan'),
                TextEntry::make('outlet.name')->label('Outlet')->placeholder('Entitas'),
                TextEntry::make('subtotal')->label('DPP')
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextEntry::make('tax_amount')->label('PPN keluaran')
                    ->formatStateUsing(fn ($state, SalesInvoice $record) => $record->has_tax_invoice
                        ? MenuFields::rupiah((string) $state).' · '.($record->tax_invoice_no ?? '')
                        : 'Tanpa faktur pajak'),
                TextEntry::make('total')->label('Jumlah tagihan')
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextEntry::make('paid_amount')->label('Sudah diterima')
                    ->formatStateUsing(fn ($state, SalesInvoice $record) => MenuFields::rupiah((string) $state)
                        .($record->outstanding()->isPositive()
                            ? ' · sisa '.MenuFields::rupiah((string) $record->outstanding()->toScale(2)) : '')),
                TextEntry::make('description')->label('Keterangan')->columnSpan(2),
                TextEntry::make('journal.number')->label('Jurnal')->placeholder('-')
                    ->formatStateUsing(fn ($state, SalesInvoice $record) => $record->journal === null ? '-'
                        : $record->journal->number.' ('.(Journal::STATUS_LABEL[$record->journal->status] ?? '-').')'),
            ]),
            ViewEntry::make('lines')->view('filament.treasury.sales-invoice-lines')->columnSpanFull(),
            ViewEntry::make('receipts')->view('filament.treasury.sales-invoice-receipts')->columnSpanFull(),
            ViewEntry::make('attachments')->view('filament.documents.attachments')
                ->viewData(['ownerType' => DocumentAttachment::SALES_INVOICE])->columnSpanFull(),
        ]);
    }

    /** @param  callable(User): mixed  $do */
    public static function run(callable $do, string $sukses): void
    {
        $user = TreasuryAccess::user();
        if ($user === null || ! TreasuryAccess::canManage()) {
            Notification::make()->danger()->title('Tidak berwenang')->send();

            return;
        }
        try {
            $do($user);
        } catch (TreasuryException|AccountingException $e) {
            Notification::make()->danger()->title('Tidak dapat diproses')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title($sukses)->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesInvoices::route('/'),
            'create' => Pages\CreateSalesInvoice::route('/baru'),
            'edit' => Pages\EditSalesInvoice::route('/{record}/ubah'),
            'view' => Pages\ViewSalesInvoice::route('/{record}'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function linesFor(SalesInvoice $invoice): array
    {
        return $invoice->lines()->get()->map(fn ($l) => [
            'description' => $l->description,
            'account_id' => $l->account_id,
            'quantity' => (string) $l->quantity,
            'unit_price' => (string) $l->unit_price,
            'amount' => (string) $l->amount,
        ])->all();
    }
}
