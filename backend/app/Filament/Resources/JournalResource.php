<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JournalResource\Pages;
use App\Filament\Support\AccountingAccess;
use App\Filament\Support\MenuFields;
use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Domain\Models\JournalLine;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Jurnal umum (ACC-05). Jurnal terposting hanya bisa dilihat; koreksinya lewat jurnal balik. */
class JournalResource extends Resource
{
    protected static ?string $model = Journal::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Akuntansi';

    protected static ?string $modelLabel = 'jurnal';

    protected static ?string $pluralModelLabel = 'Jurnal Umum';

    protected static ?string $slug = 'pembukuan/jurnal';

    protected static ?int $navigationSort = 2;

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
        return AccountingAccess::canManage() && $record instanceof Journal && $record->isDraft();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canEdit($record);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('journal_date')->label('Tanggal')->required()
                ->default(fn () => CarbonImmutable::now(config('app.display_timezone'))->format('Y-m-d')),
            TextInput::make('description')->label('Keterangan')->required()->minLength(3)->maxLength(300)
                ->columnSpan(2),
            Repeater::make('lines')
                ->label('Baris jurnal')
                ->columnSpanFull()
                ->minItems(2)
                ->defaultItems(2)
                ->live(onBlur: true)
                ->addActionLabel('Tambah baris')
                /*
                 * Satu baris jurnal = satu baris di layar. Tata letak tiga kolom membuat tiap baris
                 * memakan tinggi tiga baris, dan jurnal yang wajar saja (6–8 baris) langsung tidak
                 * muat satu layar — padahal yang paling sering dicari saat mengisi jurnal justru
                 * "sudah seimbang atau belum", yang ada di paling bawah.
                 */
                ->schema([
                    Select::make('account_id')->label('Akun')->required()->searchable()->columnSpan(4)
                        // Hanya akun daun yang aktif: akun induk dipakai menjumlah di laporan.
                        ->options(fn () => Account::query()->where('is_postable', true)->where('is_active', true)
                            ->orderBy('code')->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all()),
                    TextInput::make('debit')->label('Debit')->numeric()->minValue(0)->default('0')->live(onBlur: true)->columnSpan(2),
                    TextInput::make('credit')->label('Kredit')->numeric()->minValue(0)->default('0')->live(onBlur: true)->columnSpan(2),
                    Select::make('outlet_id')->label('Outlet')->searchable()->placeholder('Semua')->columnSpan(2)
                        ->options(fn () => Outlet::query()->orderBy('name')->pluck('name', 'id')->all()),
                    TextInput::make('memo')->label('Catatan')->maxLength(300)->columnSpan(2),
                ])->columns(12),
            /*
             * Selisih ditampilkan sambil mengetik. Tanpa ini kasir pembukuan baru tahu jurnalnya
             * timpang setelah menekan Simpan, dan harus mencari sendiri baris mana yang salah.
             */
            Placeholder::make('balance')->label('Keseimbangan')->columnSpanFull()
                ->content(function (Get $get): string {
                    [$d, $c] = self::sides($get('lines') ?? []);
                    $selisih = $d->minus($c);

                    // Jurnal kosong bukan jurnal seimbang; menyebutnya begitu memberi rasa aman palsu.
                    if ($d->isZero() && $c->isZero()) {
                        return 'Belum ada nilai yang diisi.';
                    }

                    return $selisih->isZero()
                        ? 'Seimbang — debit dan kredit sama-sama '.MenuFields::rupiah((string) $d->toScale(2))
                        : 'Belum seimbang · debit '.MenuFields::rupiah((string) $d->toScale(2))
                          .' · kredit '.MenuFields::rupiah((string) $c->toScale(2))
                          .' · selisih '.MenuFields::rupiah((string) $selisih->abs()->toScale(2));
                }),
        ])->columns(3);
    }

    /**
     * @return array{0: BigDecimal, 1: BigDecimal}
     */
    public static function sides(mixed $lines): array
    {
        $d = BigDecimal::zero();
        $c = BigDecimal::zero();
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $d = $d->plus(self::number($line['debit'] ?? '0'));
            $c = $c->plus(self::number($line['credit'] ?? '0'));
        }

        return [$d, $c];
    }

    private static function number(mixed $value): BigDecimal
    {
        $text = is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';

        return preg_match('/^\d+(\.\d+)?$/', $text) === 1 ? BigDecimal::of($text) : BigDecimal::zero();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withSum('lines as total', 'debit')->with('creator:id,name'))
            ->columns([
                TextColumn::make('journal_date')->label('Tanggal')->date('d M Y')->sortable(),
                TextColumn::make('number')->label('No. jurnal')->fontFamily('mono')->searchable(),
                TextColumn::make('description')->label('Keterangan')->wrap()->searchable(),
                TextColumn::make('total')->label('Nilai')->alignEnd()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) ($state ?? '0'))),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => Journal::STATUS_LABEL[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Journal::POSTED => 'success',
                        Journal::DRAFT => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('source')->label('Sumber')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('creator.name')->label('Dibuat oleh')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('journal_date', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Status')->options(Journal::STATUS_LABEL),
                Filter::make('periode')
                    ->form([
                        DatePicker::make('from')->label('Dari tanggal'),
                        DatePicker::make('until')->label('Sampai tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->where('journal_date', '>=', $v))
                        ->when($data['until'] ?? null, fn ($q, $v) => $q->where('journal_date', '<=', $v))),
            ])
            ->actions([
                ViewAction::make()->label('Detail'),
                Action::make('post')
                    ->label('Posting')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Journal $record) => $record->isDraft() && AccountingAccess::canManage())
                    ->requiresConfirmation()
                    ->modalHeading('Posting jurnal ke buku besar')
                    ->modalDescription('Setelah diposting, jurnal ini tidak dapat diubah lagi. Koreksi hanya bisa lewat jurnal balik.')
                    ->modalSubmitActionLabel('Posting jurnal')
                    ->action(fn (Journal $record) => self::run(fn (User $by) => app(JournalService::class)->post($record, $by), 'Jurnal diposting.')),
                Action::make('reverse')
                    ->label('Jurnal balik')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    ->visible(fn (Journal $record) => $record->status === Journal::POSTED && AccountingAccess::canManage())
                    ->modalHeading('Buat jurnal balik')
                    ->modalDescription('Jurnal asal tetap utuh; koreksinya berupa jurnal baru yang menihilkannya.')
                    ->form([
                        DatePicker::make('on')->label('Tanggal pembalikan')
                            ->default(fn () => CarbonImmutable::now(config('app.display_timezone'))->format('Y-m-d'))
                            ->helperText('Bila periode jurnal asal sudah ditutup, catat pembalikannya di periode berjalan.'),
                        TextInput::make('reason')->label('Alasan')->required()->minLength(3)->maxLength(300),
                    ])
                    ->action(fn (Journal $record, array $data) => self::run(
                        fn (User $by) => app(JournalService::class)->reverse($record, $by, (string) $data['reason'],
                            isset($data['on']) ? CarbonImmutable::parse((string) $data['on']) : null),
                        'Jurnal balik dibuat dan langsung diposting.')),
            ])
            ->emptyStateHeading('Belum ada jurnal')
            ->emptyStateDescription('Buat jurnal umum, atau tunggu jurnal otomatis dari penjualan saat modul itu menyusul.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('No. jurnal')->fontFamily('mono'),
                TextEntry::make('journal_date')->label('Tanggal')->date('d M Y'),
                TextEntry::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => Journal::STATUS_LABEL[$state] ?? $state),
                TextEntry::make('source')->label('Sumber'),
                TextEntry::make('description')->label('Keterangan')->columnSpanFull(),
                TextEntry::make('creator.name')->label('Dibuat oleh')->placeholder('-'),
                TextEntry::make('poster.name')->label('Diposting oleh')->placeholder('-'),
                TextEntry::make('posted_at')->label('Waktu posting')->dateTime('d M Y H.i')->placeholder('-'),
                TextEntry::make('reverses.number')->label('Membalik jurnal')->placeholder('-'),
            ]),
            ViewEntry::make('lines')->view('filament.accounting.journal-lines')->columnSpanFull(),
        ]);
    }

    /**
     * Galat aturan ditampilkan sebagai notifikasi, bukan layar 500: dua orang yang memposting
     * jurnal yang sama bersamaan adalah kejadian biasa, bukan kerusakan sistem.
     *
     * @param  callable(User): mixed  $do
     */
    private static function run(callable $do, string $sukses): void
    {
        $user = AccountingAccess::user();
        if ($user === null || ! AccountingAccess::canManage()) {
            Notification::make()->danger()->title('Tidak berwenang')->send();

            return;
        }
        try {
            $do($user);
        } catch (AccountingException $e) {
            Notification::make()->danger()->title('Tidak dapat diproses')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title($sukses)->send();
    }

    /** @return Builder<Journal> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJournals::route('/'),
            'create' => Pages\CreateJournal::route('/baru'),
            'edit' => Pages\EditJournal::route('/{record}/ubah'),
            'view' => Pages\ViewJournal::route('/{record}'),
        ];
    }

    /** @return list<array{account_id: string, debit: string, credit: string, outlet_id: string|null, memo: string|null}> Baris jurnal untuk form (draft). */
    public static function linesFor(Journal $journal): array
    {
        return JournalLine::query()->where('journal_id', $journal->id)->orderBy('line_no')->get()
            ->map(fn (JournalLine $l) => [
                'account_id' => $l->account_id,
                'debit' => (string) $l->debit,
                'credit' => (string) $l->credit,
                'outlet_id' => $l->outlet_id,
                'memo' => $l->memo,
            ])->all();
    }
}
