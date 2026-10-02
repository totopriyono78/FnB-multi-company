<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseInvoiceResource\Pages;
use App\Filament\Support\MenuFields;
use App\Filament\Support\TreasuryAccess;
use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Purchasing\Domain\Models\GoodsReceipt;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Shared\Application\AttachmentStore;
use App\Modules\Shared\Application\DocumentAttachments;
use App\Modules\Shared\Domain\Models\DocumentAttachment;
use App\Modules\Tenancy\Domain\Models\Outlet;
use App\Modules\Treasury\Application\PurchaseInvoiceService;
use App\Modules\Treasury\Application\TreasuryException;
use App\Modules\Treasury\Domain\Models\PurchaseInvoice;
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
 * Faktur pembelian — tagihan masuk dari supplier (AP-02, TAX-02).
 *
 * Dua jalan masuk, satu layar: tarik dari penerimaan barang yang sudah ada, atau ketik sendiri untuk
 * tagihan yang tidak pernah punya penerimaan barang — listrik, sewa, jasa. Memaksa keduanya lewat
 * satu jalan berarti salah satunya akan lari ke jalan yang tidak terkontrol.
 */
class PurchaseInvoiceResource extends Resource
{
    protected static ?string $model = PurchaseInvoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Kas & Hutang';

    protected static ?string $modelLabel = 'faktur pembelian';

    protected static ?string $pluralModelLabel = 'Faktur Pembelian';

    protected static ?string $slug = 'hutang/faktur-pembelian';

    protected static ?int $navigationSort = 4;

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
        return TreasuryAccess::canManage() && $record instanceof PurchaseInvoice && $record->isEditable();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canEdit($record);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! TreasuryAccess::canView()) {
            return null;
        }
        $jumlah = PurchaseInvoice::query()->where('status', PurchaseInvoice::ISSUED)->count();

        return $jumlah > 0 ? (string) $jumlah : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('supplier_id')->label('Supplier')->required()->searchable()->live()
                ->options(fn () => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                /*
                 * Mengganti supplier mengosongkan penerimaan barang yang sudah dipilih: penerimaan
                 * milik supplier lain tidak boleh ikut terbawa, dan layanan memang menolaknya —
                 * lebih baik kosong di layar daripada ditolak saat Simpan.
                 */
                ->afterStateUpdated(fn (Set $set) => $set('goods_receipt_id', null)),
            Select::make('goods_receipt_id')->label('Dari penerimaan barang')->searchable()->live()
                ->placeholder('Tanpa penerimaan barang (tagihan jasa, listrik, sewa)')
                ->helperText('Hanya penerimaan yang belum pernah difakturkan yang muncul di sini.')
                ->options(fn (Get $get) => app(PurchaseInvoiceService::class)
                    ->openGoodsReceipts((string) ($get('supplier_id') ?? ''))
                    ->mapWithKeys(fn (GoodsReceipt $gr) => [$gr->id => $gr->number.' · '
                        .$gr->business_date->translatedFormat('d M Y').' · '.MenuFields::rupiah((string) $gr->total)])
                    ->all())
                ->afterStateUpdated(function (Set $set, ?string $state): void {
                    if ($state === null || $state === '') {
                        return;
                    }
                    $receipt = GoodsReceipt::query()->find($state);
                    if ($receipt === null) {
                        return;
                    }
                    // Barisnya diisikan dari penerimaan, lalu boleh disunting: yang menagih supplier,
                    // yang menerima kita, dan keduanya tidak selalu sama persis.
                    $set('lines', app(PurchaseInvoiceService::class)->linesFromGoodsReceipt($receipt));
                    $set('outlet_id', $receipt->outlet_id);
                    if ($receipt->supplier_invoice_no !== null) {
                        $set('supplier_invoice_no', $receipt->supplier_invoice_no);
                    }
                })->columnSpan(2),
            DatePicker::make('invoice_date')->label('Tanggal faktur')->required()
                ->default(fn () => CarbonImmutable::now(config('app.display_timezone'))->format('Y-m-d')),
            DatePicker::make('due_date')->label('Jatuh tempo')
                ->helperText('Kosongkan untuk mengikuti termin supplier.'),
            TextInput::make('supplier_invoice_no')->label('No. faktur supplier')->maxLength(60)
                ->helperText('Dipakai mencegah satu tagihan masuk dua kali.'),
            Select::make('outlet_id')->label('Outlet')->searchable()->placeholder('Entitas')
                ->options(fn () => Outlet::query()->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('description')->label('Keterangan')->required()->minLength(3)->maxLength(300)
                ->columnSpan(2),
            Repeater::make('lines')->label('Baris faktur')->columnSpanFull()
                ->minItems(1)->defaultItems(1)->live(onBlur: true)
                ->addActionLabel('Tambah baris')
                ->schema([
                    TextInput::make('description')->label('Keterangan')->required()->maxLength(200)->columnSpan(4),
                    Select::make('account_id')->label('Akun')->required()->searchable()->columnSpan(3)
                        ->options(fn () => Account::query()->where('is_postable', true)->where('is_active', true)
                            ->orderBy('code')->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all()),
                    TextInput::make('quantity')->label('Jumlah')->numeric()->default('1')->columnSpan(1),
                    TextInput::make('unit_price')->label('Harga satuan')->numeric()->default('0')->columnSpan(2)
                        ->live(onBlur: true)
                        // Nilai baris dihitung dari jumlah × harga, tetapi tetap boleh dikoreksi:
                        // pembulatan di faktur supplier sering tidak persis sama dengan hitungan kita.
                        ->afterStateUpdated(fn (Set $set, Get $get) => $set('amount',
                            (string) self::lineTotal($get('quantity'), $get('unit_price')))),
                    TextInput::make('amount')->label('Nilai')->numeric()->required()->columnSpan(2),
                ])->columns(12),
            Toggle::make('has_tax_invoice')->label('Ada faktur pajak (PPN masukan dikreditkan)')->live()
                ->default(fn (Get $get) => (bool) Supplier::query()->whereKey($get('supplier_id'))->value('is_pkp'))
                ->helperText('Bawaannya mengikuti status PKP supplier. Matikan bila tagihan ini tidak berfaktur pajak.'),
            TextInput::make('tax_amount')->label('PPN masukan (Rp)')->numeric()->default('0')->live(onBlur: true)
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

    private static function lineTotal(mixed $qty, mixed $price): string
    {
        return (string) self::number($qty)->multipliedBy(self::number($price))->toScale(2);
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
                'supplier:id,name', 'outlet:id,name', 'journal:id,number,status',
            ]))
            ->columns([
                TextColumn::make('number')->label('No. faktur')->fontFamily('mono')->searchable(),
                TextColumn::make('invoice_date')->label('Tanggal')->date('d M Y')->sortable(),
                TextColumn::make('supplier.name')->label('Supplier')->searchable()->wrap()
                    ->description(fn (PurchaseInvoice $record) => $record->supplier_invoice_no),
                TextColumn::make('description')->label('Keterangan')->wrap()->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total')->label('Nilai')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('due_date')->label('Jatuh tempo')->date('d M Y')->placeholder('-')->sortable()
                    // Tunggakan ditandai di kolomnya sendiri: daftar hutang yang tidak mengatakan mana
                    // yang sudah lewat tempo hanya daftar, bukan alat kerja.
                    ->description(fn (PurchaseInvoice $record) => $record->status === PurchaseInvoice::ISSUED
                        && $record->daysOverdue() > 0 ? 'telat '.$record->daysOverdue().' hari' : null)
                    ->color(fn (PurchaseInvoice $record) => $record->status === PurchaseInvoice::ISSUED
                        && $record->daysOverdue() > 0 ? 'danger' : null),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => PurchaseInvoice::STATUS_LABEL[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        PurchaseInvoice::PAID => 'success',
                        PurchaseInvoice::ISSUED => 'warning',
                        PurchaseInvoice::CANCELLED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('paid_amount')->label('Dibayar')->alignEnd()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state))
                    ->description(fn (PurchaseInvoice $record) => $record->outstanding()->isPositive()
                        && $record->status === PurchaseInvoice::ISSUED
                        ? 'sisa '.MenuFields::rupiah((string) $record->outstanding()->toScale(2)) : null),
            ])
            ->defaultSort('invoice_date', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Status')->options(PurchaseInvoice::STATUS_LABEL),
                SelectFilter::make('supplier_id')->label('Supplier')
                    ->options(fn () => Supplier::query()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('jatuh_tempo')->label('Sudah lewat jatuh tempo')
                    ->query(fn (Builder $query) => $query->where('status', PurchaseInvoice::ISSUED)
                        ->whereNotNull('due_date')->whereDate('due_date', '<', now())),
                Filter::make('periode')
                    ->form([
                        DatePicker::make('from')->label('Dari tanggal'),
                        DatePicker::make('until')->label('Sampai tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->where('invoice_date', '>=', $v))
                        ->when($data['until'] ?? null, fn ($q, $v) => $q->where('invoice_date', '<=', $v))),
            ])
            ->actions([
                ViewAction::make()->label('Detail'),
                EditAction::make()->label('Ubah'),
                Action::make('issue')
                    ->label('Terbitkan')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (PurchaseInvoice $record) => $record->isEditable() && TreasuryAccess::canManage())
                    ->requiresConfirmation()
                    ->modalHeading('Terbitkan faktur')
                    ->modalDescription('Hutang usaha muncul dan bebannya diakui sejak tanggal faktur. '
                        .'Jurnalnya lahir sebagai draft dan tetap harus diposting orang lain. '
                        .'Setelah diterbitkan, faktur tidak bisa diubah lagi.')
                    ->modalSubmitActionLabel('Terbitkan faktur')
                    ->action(fn (PurchaseInvoice $record) => self::run(
                        fn (User $by) => app(PurchaseInvoiceService::class)->issue($record, $by),
                        'Faktur diterbitkan, hutangnya tercatat.')),
                Action::make('attach')
                    ->label('Lampiran')->icon('heroicon-o-paper-clip')->color('gray')
                    ->visible(fn () => TreasuryAccess::canManage())
                    ->modalHeading('Lampirkan faktur & bukti')
                    ->modalDescription('Foto atau pindaian faktur supplier dan faktur pajaknya (JPG/PNG/WebP/PDF).')
                    ->modalSubmitActionLabel('Simpan lampiran')
                    ->form([
                        FileUpload::make('files')->label('Berkas')->multiple()->required()
                            ->acceptedFileTypes(AttachmentStore::MIMES)
                            ->maxSize(fn () => app(AttachmentStore::class)->maxKb())
                            ->storeFiles(false),
                    ])
                    ->action(fn (PurchaseInvoice $record, array $data) => self::run(function (User $by) use ($record, $data): void {
                        foreach ((array) ($data['files'] ?? []) as $file) {
                            if ($file instanceof UploadedFile) {
                                app(DocumentAttachments::class)->attach(DocumentAttachment::PURCHASE_INVOICE, $record, $file, $by);
                            }
                        }
                    }, 'Lampiran tersimpan.')),
                Action::make('cancel')
                    ->label('Batalkan')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (PurchaseInvoice $record) => $record->isEditable() && TreasuryAccess::canManage())
                    ->modalHeading('Batalkan faktur draft')
                    ->form([TextInput::make('reason')->label('Alasan')->required()->minLength(3)->maxLength(300)])
                    ->modalSubmitActionLabel('Batalkan faktur')
                    ->action(fn (PurchaseInvoice $record, array $data) => self::run(
                        fn (User $by) => app(PurchaseInvoiceService::class)->cancel($record, $by, (string) $data['reason']),
                        'Faktur dibatalkan.')),
            ])
            ->emptyStateHeading('Belum ada faktur pembelian')
            ->emptyStateDescription('Catat tagihan masuk dari supplier di sini. Bebannya diakui saat faktur diterbitkan, '
                .'bukan saat dibayar — dan pembayarannya tetap lewat Pengajuan Pembayaran (SPPK).');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('No. faktur')->fontFamily('mono'),
                TextEntry::make('invoice_date')->label('Tanggal')->date('d M Y'),
                TextEntry::make('due_date')->label('Jatuh tempo')->date('d M Y')->placeholder('-'),
                TextEntry::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => PurchaseInvoice::STATUS_LABEL[$state] ?? $state),
                TextEntry::make('supplier.name')->label('Supplier'),
                TextEntry::make('supplier_invoice_no')->label('No. faktur supplier')->placeholder('-'),
                TextEntry::make('goodsReceipt.number')->label('Dari penerimaan')->placeholder('-'),
                TextEntry::make('outlet.name')->label('Outlet')->placeholder('Entitas'),
                TextEntry::make('subtotal')->label('DPP')
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextEntry::make('tax_amount')->label('PPN masukan')
                    ->formatStateUsing(fn ($state, PurchaseInvoice $record) => $record->has_tax_invoice
                        ? MenuFields::rupiah((string) $state).' · '.($record->tax_invoice_no ?? '')
                        : 'Tanpa faktur pajak'),
                TextEntry::make('total')->label('Jumlah tagihan')
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextEntry::make('paid_amount')->label('Sudah dibayar')
                    ->formatStateUsing(fn ($state, PurchaseInvoice $record) => MenuFields::rupiah((string) $state)
                        .($record->outstanding()->isPositive()
                            ? ' · sisa '.MenuFields::rupiah((string) $record->outstanding()->toScale(2)) : '')),
                TextEntry::make('description')->label('Keterangan')->columnSpan(3),
                TextEntry::make('journal.number')->label('Jurnal')->placeholder('-')
                    // journal_id boleh kosong (faktur draft); relasinya sendiri tidak pernah separuh ada.
                    ->formatStateUsing(fn ($state, PurchaseInvoice $record) => $record->journal === null ? '-'
                        : $record->journal->number.' ('.(Journal::STATUS_LABEL[$record->journal->status] ?? '-').')'),
            ]),
            ViewEntry::make('lines')->view('filament.treasury.invoice-lines')->columnSpanFull(),
            ViewEntry::make('payments')->view('filament.treasury.invoice-payments')->columnSpanFull(),
            ViewEntry::make('attachments')->view('filament.documents.attachments')
                ->viewData(['ownerType' => DocumentAttachment::PURCHASE_INVOICE])->columnSpanFull(),
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
            'index' => Pages\ListPurchaseInvoices::route('/'),
            'create' => Pages\CreatePurchaseInvoice::route('/baru'),
            'edit' => Pages\EditPurchaseInvoice::route('/{record}/ubah'),
            'view' => Pages\ViewPurchaseInvoice::route('/{record}'),
        ];
    }

    /** @return list<array<string, mixed>> Baris faktur untuk form (draft). */
    public static function linesFor(PurchaseInvoice $invoice): array
    {
        return $invoice->lines()->get()->map(fn ($l) => [
            'description' => $l->description,
            'account_id' => $l->account_id,
            'ingredient_id' => $l->ingredient_id,
            'quantity' => (string) $l->quantity,
            'unit_price' => (string) $l->unit_price,
            'amount' => (string) $l->amount,
        ])->all();
    }
}
