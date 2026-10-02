<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentRequestResource\Pages;
use App\Filament\Support\MenuFields;
use App\Filament\Support\PaymentAccess;
use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Documents\Application\ApprovalMatrix;
use App\Modules\Documents\Application\DocumentException;
use App\Modules\Documents\Application\PaymentAdviceService;
use App\Modules\Documents\Application\PaymentRequestService;
use App\Modules\Documents\Domain\Models\ApprovalRule;
use App\Modules\Documents\Domain\Models\PaymentRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Shared\Application\AttachmentStore;
use App\Modules\Shared\Application\DocumentAttachments;
use App\Modules\Shared\Domain\Models\DocumentAttachment;
use App\Modules\Tenancy\Domain\Models\Outlet;
use App\Modules\Treasury\Application\PurchaseInvoiceService;
use App\Modules\Treasury\Domain\Models\PurchaseInvoice;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
 * SPPK — pengajuan pembayaran dengan persetujuan berjenjang (DOC-04, DOC-05, DOC-10).
 *
 * Inilah layar yang menggantikan tumpukan nota yang dibawa ke pusat. Satu baris di daftar menjawab
 * pertanyaan yang paling sering diajukan tentang sebuah pengajuan: berapa, siapa yang menerima,
 * sudah ditandatangani sampai tingkat berapa, dan sudah dibayar berapa.
 */
class PaymentRequestResource extends Resource
{
    protected static ?string $model = PaymentRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?string $navigationGroup = 'Dokumen';

    protected static ?string $modelLabel = 'pengajuan pembayaran';

    protected static ?string $pluralModelLabel = 'Pengajuan Pembayaran (SPPK)';

    protected static ?string $slug = 'dokumen/pengajuan-pembayaran';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return PaymentAccess::canView();
    }

    public static function canCreate(): bool
    {
        return PaymentAccess::canRequest();
    }

    public static function canEdit(Model $record): bool
    {
        return PaymentAccess::canRequest() && $record instanceof PaymentRequest && $record->isEditable();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canEdit($record);
    }

    /** Jumlah yang menunggu tanda tangan — supaya antrian terlihat tanpa membuka layarnya. */
    public static function getNavigationBadge(): ?string
    {
        if (! PaymentAccess::canView()) {
            return null;
        }
        $jumlah = PaymentRequest::query()->where('status', PaymentRequest::SUBMITTED)->count();

        return $jumlah > 0 ? (string) $jumlah : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('request_date')->label('Tanggal pengajuan')->required()
                ->default(fn () => CarbonImmutable::now(config('app.display_timezone'))->format('Y-m-d')),
            DatePicker::make('due_date')->label('Jatuh tempo')
                ->helperText('Kosongkan bila tidak ada tenggat.'),
            Select::make('outlet_id')->label('Outlet')->searchable()->placeholder('Entitas (tanpa outlet)')
                ->options(fn () => Outlet::query()->orderBy('name')->pluck('name', 'id')->all())
                ->helperText('Beban ikut outlet ini di laporan laba rugi per outlet.'),
            Select::make('supplier_id')->label('Supplier')->searchable()->live()
                ->options(fn () => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->helperText('Pilih supplier, atau kosongkan dan tulis nama penerima sendiri.'),
            TextInput::make('payee_name')->label('Nama penerima')->maxLength(150)
                // Disembunyikan bila supplier dipilih: namanya disalin dari master supplier, dan dua
                // kolom nama yang bisa berbeda isinya hanya mengundang pertanyaan mana yang benar.
                ->visible(fn (Get $get) => ($get('supplier_id') ?? '') === '')
                ->required(fn (Get $get) => ($get('supplier_id') ?? '') === ''),
            /*
             * Pelunasan faktur pembelian (AP-03). Memilih faktur di sini mengisi sendiri nilainya dan
             * memindahkan akunnya ke Utang Usaha — karena bebannya sudah diakui saat faktur
             * diterbitkan, dan membebankannya lagi saat dibayar berarti mencatat satu pengeluaran
             * dua kali. Itu kesalahan yang paling mudah terjadi dan paling sulit ditemukan, jadi
             * layar ini yang mengurusnya, bukan ingatan orang.
             */
            /*
             * TIDAK dipasangi ->dehydrated(false): kolom ini memang bukan milik model, tetapi
             * halaman Create/Edit-lah yang menyimpannya ke tabel alokasi, dan ia hanya bisa
             * melakukannya kalau isiannya ikut sampai ke sana. Layanan SPPK mengabaikan kunci yang
             * tidak dikenalnya, jadi ikut terbawa tidak merusak apa pun.
             */
            Select::make('invoice_ids')->label('Melunasi faktur')->multiple()->searchable()->live()
                ->visible(fn (Get $get) => ($get('supplier_id') ?? '') !== '')
                ->helperText('Hanya faktur supplier ini yang belum lunas. Kosongkan untuk pembayaran tanpa faktur.')
                ->options(fn (Get $get) => self::openInvoices((string) ($get('supplier_id') ?? '')))
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    $ids = array_values(array_filter((array) $state));
                    if ($ids === []) {
                        return;
                    }
                    $total = BigDecimal::zero();
                    foreach (PurchaseInvoice::query()->whereKey($ids)->get() as $invoice) {
                        $total = $total->plus($invoice->outstanding());
                    }
                    $set('amount', (string) $total->toScale(2));
                    $hutang = Account::query()->where('code', PurchaseInvoiceService::PAYABLE_CODE)->value('id');
                    if (is_string($hutang)) {
                        $set('expense_account_id', $hutang);
                    }
                })->columnSpan(2),
            TextInput::make('amount')->label('Nilai (Rp)')->numeric()->required()->minValue(1)->live(onBlur: true),
            Select::make('expense_account_id')->label('Dibebankan ke akun')->required()->searchable()
                ->options(fn () => Account::query()->where('is_postable', true)->where('is_active', true)
                    ->orderBy('code')->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all())
                ->helperText('Akun yang akan didebit saat pembayarannya dijurnal. '
                    .'Untuk pelunasan faktur, akun ini Utang Usaha — bukan akun beban.'),
            Textarea::make('description')->label('Keperluan')->required()->minLength(3)->maxLength(300)
                ->rows(2)->columnSpanFull(),
            /*
             * Tanda tangan yang akan dituntut ditampilkan sebelum diajukan. Tanpa ini, pengaju baru
             * tahu pengajuannya butuh tanda tangan pemilik setelah menunggu dua hari — dan itu
             * biasanya berakhir dengan pengajuan dipecah-pecah supaya masuk band yang lebih rendah.
             */
            Placeholder::make('wewenang')->label('Tanda tangan yang akan dibutuhkan')->columnSpanFull()
                ->content(fn (Get $get) => self::authorityHint($get('amount'))),
        ])->columns(2);
    }

    private static function authorityHint(mixed $amount): string
    {
        $text = is_string($amount) || is_int($amount) || is_float($amount) ? trim((string) $amount) : '';
        if (preg_match('/^\d+(\.\d{1,2})?$/', $text) !== 1 || BigDecimal::of($text)->isZero()) {
            return 'Isi nilainya untuk melihat siapa saja yang harus menandatangani.';
        }

        try {
            $perlu = app(ApprovalMatrix::class)->requiredFor(ApprovalRule::PAYMENT_REQUEST, BigDecimal::of($text));
        } catch (DocumentException $e) {
            return $e->getMessage();
        }

        $peran = ApprovalMatrix::roleOptions();
        $daftar = array_map(fn (array $a) => "tingkat {$a['level']}: ".($peran[$a['role']] ?? $a['role']), $perlu);

        return count($perlu).' tanda tangan — '.implode(', ', $daftar).'.';
    }

    /**
     * Faktur supplier ini yang masih berhutang, siap ditunjuk untuk dilunasi.
     *
     * @return array<string, string>
     */
    private static function openInvoices(string $supplierId): array
    {
        if ($supplierId === '') {
            return [];
        }

        return PurchaseInvoice::query()
            ->where('supplier_id', $supplierId)
            ->where('status', PurchaseInvoice::ISSUED)
            ->orderByRaw('due_date ASC NULLS LAST')
            ->get()
            ->mapWithKeys(fn (PurchaseInvoice $i) => [$i->id => $i->number
                .' · '.($i->due_date?->translatedFormat('d M Y') ?? 'tanpa tempo')
                .' · sisa '.MenuFields::rupiah((string) $i->outstanding()->toScale(2))])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['requester:id,name', 'outlet:id,name'])->withCount('approvals'))
            ->columns([
                TextColumn::make('number')->label('No. SPPK')->fontFamily('mono')->searchable(),
                TextColumn::make('request_date')->label('Tanggal')->date('d M Y')->sortable(),
                TextColumn::make('payee_name')->label('Penerima')->searchable()->wrap()
                    ->description(fn (PaymentRequest $record) => $record->outlet?->name),
                TextColumn::make('description')->label('Keperluan')->wrap()->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('amount')->label('Nilai')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => PaymentRequest::STATUS_LABEL[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        PaymentRequest::PAID => 'success',
                        PaymentRequest::APPROVED => 'info',
                        PaymentRequest::SUBMITTED => 'warning',
                        PaymentRequest::CANCELLED, PaymentRequest::REJECTED => 'danger',
                        default => 'gray',
                    }),
                // Kolom yang paling dicari pemeriksa: sudah sampai tanda tangan ke berapa.
                TextColumn::make('approvals_count')->label('Tanda tangan')->alignCenter()
                    ->formatStateUsing(fn ($state, PaymentRequest $record) => $record->status === PaymentRequest::DRAFT
                        ? '-' : $state.' / '.$record->required_levels),
                TextColumn::make('paid_amount')->label('Dibayar')->alignEnd()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state))
                    ->description(fn (PaymentRequest $record) => $record->outstanding()->isPositive()
                        && in_array($record->status, [PaymentRequest::APPROVED, PaymentRequest::PAID], true)
                        ? 'sisa '.MenuFields::rupiah((string) $record->outstanding()->toScale(2)) : null),
                TextColumn::make('requester.name')->label('Pengaju')->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('request_date', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Status')->options(PaymentRequest::STATUS_LABEL),
                SelectFilter::make('outlet_id')->label('Outlet')
                    ->options(fn () => Outlet::query()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('periode')
                    ->form([
                        DatePicker::make('from')->label('Dari tanggal'),
                        DatePicker::make('until')->label('Sampai tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->where('request_date', '>=', $v))
                        ->when($data['until'] ?? null, fn ($q, $v) => $q->where('request_date', '<=', $v))),
            ])
            ->actions(self::rowActions())
            ->emptyStateHeading('Belum ada pengajuan pembayaran')
            ->emptyStateDescription('Buat SPPK untuk pembayaran ke pihak ketiga. '
                .'Setelah disetujui sesuai batas wewenang, pembayarannya diterbitkan sebagai advis bayar dan langsung terjurnal.');
    }

    /**
     * Aksi baris — dipakai juga oleh antrian verifikasi, supaya satu dokumen berperilaku sama
     * di mana pun ia muncul.
     *
     * @return list<Action>
     */
    public static function rowActions(): array
    {
        return [
            ViewAction::make()->label('Detail'),
            /*
             * Tombol Ubah ada di baris, bukan hanya di layar detail. Pengajuan yang ditolak kembali
             * menjadi draft justru supaya bisa diperbaiki; kalau jalan memperbaikinya tidak terlihat
             * dari daftar, orang akan membuat pengajuan baru dan yang lama mengendap selamanya.
             * Filament menyembunyikannya sendiri ketika canEdit() menolak.
             */
            EditAction::make()->label('Ubah'),
            Action::make('submit')
                ->label('Ajukan')->icon('heroicon-o-paper-airplane')->color('info')
                ->visible(fn (PaymentRequest $record) => $record->isEditable() && PaymentAccess::canRequest())
                ->requiresConfirmation()
                ->modalHeading('Ajukan untuk disetujui')
                ->modalDescription(fn (PaymentRequest $record) => self::authorityHint($record->amount)
                    .' Setelah diajukan, nilai dan akunnya terkunci sampai disetujui atau ditolak.')
                ->modalSubmitActionLabel('Ajukan SPPK')
                ->action(fn (PaymentRequest $record) => self::run(
                    fn (User $by) => app(PaymentRequestService::class)->submit($record, $by),
                    'SPPK diajukan.', PaymentAccess::canRequest())),
            Action::make('approve')
                ->label('Setujui')->icon('heroicon-o-check-badge')->color('success')
                // Disembunyikan dari orang yang pasti ditolak layanannya: pengajunya sendiri, yang
                // sudah menandatangani, atau yang perannya bukan peran tingkat berikutnya.
                ->visible(fn (PaymentRequest $record) => PaymentAccess::canApprove($record))
                ->modalHeading('Tandatangani pengajuan ini')
                ->modalDescription(fn (PaymentRequest $record) => 'Tanda tangan tingkat '
                    .($record->nextLevel() ?? 1).' dari '.$record->required_levels
                    .'. Nilai: '.MenuFields::rupiah((string) $record->amount).'.')
                ->modalSubmitActionLabel('Setujui pengajuan')
                ->form([TextInput::make('note')->label('Catatan')->maxLength(300)
                    ->helperText('Boleh dikosongkan.')])
                ->action(fn (PaymentRequest $record, array $data) => self::run(
                    fn (User $by) => app(PaymentRequestService::class)->approve($record, $by, $data['note'] ?? null),
                    'Pengajuan ditandatangani.', true)),
            Action::make('reject')
                ->label('Tolak')->icon('heroicon-o-arrow-uturn-left')->color('warning')
                ->visible(fn (PaymentRequest $record) => $record->status === PaymentRequest::SUBMITTED
                    && (PaymentAccess::canPay() || PaymentAccess::canApprove($record)))
                ->modalHeading('Kembalikan ke pengaju')
                ->modalDescription('Tanda tangan yang sudah terkumpul dihapus: dokumen yang boleh diubah lagi '
                    .'tidak boleh membawa tanda tangan atas isi yang lama.')
                ->modalSubmitActionLabel('Tolak pengajuan')
                ->form([TextInput::make('reason')->label('Alasan')->required()->minLength(3)->maxLength(300)])
                ->action(fn (PaymentRequest $record, array $data) => self::run(
                    fn (User $by) => app(PaymentRequestService::class)->reject($record, $by, (string) $data['reason']),
                    'Pengajuan dikembalikan ke pengaju.', true)),
            Action::make('pay')
                ->label('Terbitkan advis bayar')->icon('heroicon-o-banknotes')->color('success')
                ->visible(fn (PaymentRequest $record) => PaymentAccess::canPay()
                    && in_array($record->status, [PaymentRequest::APPROVED, PaymentRequest::PAID], true)
                    && $record->outstanding()->isPositive())
                ->modalHeading('Terbitkan advis bayar')
                ->modalDescription('Advis bayar langsung membuat jurnal pembayaran berstatus draft. '
                    .'Jurnalnya tetap harus diajukan dan diposting orang lain, seperti jurnal lain.')
                // Label tombol kirim modal sengaja BUKAN awalan dari label aksi barisnya
                // ("Terbitkan advis bayar"): dua tombol yang namanya saling berawalan membuat uji
                // dan pembaca layar sama-sama tidak bisa memastikan yang mana yang dimaksud.
                ->modalSubmitActionLabel('Simpan advis bayar')
                ->form([
                    DatePicker::make('paid_on')->label('Tanggal bayar')->required()
                        ->default(fn () => CarbonImmutable::now(config('app.display_timezone'))->format('Y-m-d')),
                    TextInput::make('amount')->label('Nilai dibayar (Rp)')->numeric()->required()->minValue(1)
                        // Sisa dijadikan nilai bawaan, bukan nilai penuh SPPK: pembayaran kedua atas
                        // SPPK yang sama lebih sering salah ketik daripada yang pertama.
                        ->default(fn (PaymentRequest $record) => (string) $record->outstanding()->toScale(2))
                        ->helperText(fn (PaymentRequest $record) => 'Sisa yang belum dibayar: '
                            .MenuFields::rupiah((string) $record->outstanding()->toScale(2))
                            .'. Pembayaran sebagian diperbolehkan.'),
                    Select::make('bank_account_id')->label('Dibayar dari')->required()->searchable()
                        ->options(fn () => Account::query()->where('is_postable', true)->where('is_active', true)
                            ->where('type', Account::ASSET)->orderBy('code')->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all()),
                    TextInput::make('reference')->label('No. referensi / bukti transfer')->maxLength(100),
                    TextInput::make('note')->label('Catatan')->maxLength(300),
                ])
                ->action(fn (PaymentRequest $record, array $data) => self::run(
                    fn (User $by) => app(PaymentAdviceService::class)->issue($record, [
                        'paid_on' => (string) $data['paid_on'],
                        'amount' => (string) $data['amount'],
                        'bank_account_id' => (string) $data['bank_account_id'],
                        'reference' => $data['reference'] ?? null,
                        'note' => $data['note'] ?? null,
                    ], $by),
                    'Advis bayar diterbitkan, jurnalnya dibuat sebagai draft.', PaymentAccess::canPay())),
            Action::make('attach')
                ->label('Lampiran')->icon('heroicon-o-paper-clip')->color('gray')
                ->visible(fn () => PaymentAccess::canRequest() || PaymentAccess::canPay())
                ->modalHeading('Lampirkan bukti')
                ->modalDescription('Penawaran, nota, atau faktur (JPG/PNG/WebP/PDF). '
                    .'Bukti boleh menyusul, termasuk setelah pengajuan disetujui.')
                ->modalSubmitActionLabel('Simpan lampiran')
                ->form([
                    FileUpload::make('files')->label('Berkas')->multiple()->required()
                        ->acceptedFileTypes(AttachmentStore::MIMES)
                        ->maxSize(fn () => app(AttachmentStore::class)->maxKb())
                        ->storeFiles(false),
                ])
                ->action(fn (PaymentRequest $record, array $data) => self::run(function (User $by) use ($record, $data): void {
                    foreach ((array) ($data['files'] ?? []) as $file) {
                        if ($file instanceof UploadedFile) {
                            app(DocumentAttachments::class)->attach(DocumentAttachment::PAYMENT_REQUEST, $record, $file, $by);
                        }
                    }
                }, 'Lampiran tersimpan.', PaymentAccess::canRequest() || PaymentAccess::canPay())),
            Action::make('cancel')
                ->label('Batalkan')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (PaymentRequest $record) => PaymentAccess::canPay()
                    && in_array($record->status, [PaymentRequest::DRAFT, PaymentRequest::APPROVED], true)
                    && BigDecimal::of($record->paid_amount)->isZero())
                ->modalHeading('Batalkan pengajuan')
                ->modalDescription('Pengajuan yang dibatalkan tetap tersimpan beserta jejaknya; ia hanya tidak dapat dibayar lagi.')
                ->modalSubmitActionLabel('Batalkan SPPK')
                ->form([TextInput::make('reason')->label('Alasan')->required()->minLength(3)->maxLength(300)])
                ->action(fn (PaymentRequest $record, array $data) => self::run(
                    fn (User $by) => app(PaymentRequestService::class)->cancel($record, $by, (string) $data['reason']),
                    'Pengajuan dibatalkan.', PaymentAccess::canPay())),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('No. SPPK')->fontFamily('mono'),
                TextEntry::make('request_date')->label('Tanggal')->date('d M Y'),
                TextEntry::make('due_date')->label('Jatuh tempo')->date('d M Y')->placeholder('-'),
                TextEntry::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => PaymentRequest::STATUS_LABEL[$state] ?? $state),
                TextEntry::make('payee_name')->label('Penerima'),
                TextEntry::make('outlet.name')->label('Outlet')->placeholder('Entitas'),
                TextEntry::make('amount')->label('Nilai')
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextEntry::make('paid_amount')->label('Sudah dibayar')
                    ->formatStateUsing(fn ($state, PaymentRequest $record) => MenuFields::rupiah((string) $state)
                        .($record->outstanding()->isPositive()
                            ? ' · sisa '.MenuFields::rupiah((string) $record->outstanding()->toScale(2)) : '')),
                TextEntry::make('expenseAccount.code')->label('Dibebankan ke')
                    ->formatStateUsing(fn ($state, PaymentRequest $record) => $record->expenseAccount?->label() ?? $state),
                TextEntry::make('description')->label('Keperluan')->columnSpan(3),
                TextEntry::make('requester.name')->label('Diajukan oleh')->placeholder('-'),
                TextEntry::make('submitted_at')->label('Waktu diajukan')->dateTime('d M Y H.i')->placeholder('-'),
                TextEntry::make('approved_at')->label('Disetujui lengkap')->dateTime('d M Y H.i')->placeholder('-'),
                TextEntry::make('reject_reason')->label('Alasan dikembalikan')->placeholder('-'),
            ]),
            ViewEntry::make('approvals')->view('filament.documents.approvals')->columnSpanFull(),
            ViewEntry::make('advices')->view('filament.documents.advices')->columnSpanFull(),
            ViewEntry::make('attachments')->view('filament.documents.attachments')
                ->viewData(['ownerType' => DocumentAttachment::PAYMENT_REQUEST])->columnSpanFull(),
        ]);
    }

    /**
     * Galat aturan ditampilkan sebagai notifikasi, bukan layar 500: dua orang yang menandatangani
     * pengajuan yang sama bersamaan adalah kejadian biasa, bukan kerusakan sistem.
     *
     * @param  callable(User): mixed  $do
     */
    public static function run(callable $do, string $sukses, bool $allowed): void
    {
        $user = PaymentAccess::user();
        if ($user === null || ! $allowed) {
            Notification::make()->danger()->title('Tidak berwenang')->send();

            return;
        }
        try {
            $do($user);
        } catch (DocumentException|AccountingException $e) {
            // AccountingException ikut ditangkap karena advis bayar membuat jurnal: periode yang
            // sudah ditutup atau akun yang tidak bisa dijurnal datang dari sana, bukan dari sini.
            Notification::make()->danger()->title('Tidak dapat diproses')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title($sukses)->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentRequests::route('/'),
            'create' => Pages\CreatePaymentRequest::route('/baru'),
            'edit' => Pages\EditPaymentRequest::route('/{record}/ubah'),
            'view' => Pages\ViewPaymentRequest::route('/{record}'),
        ];
    }
}
