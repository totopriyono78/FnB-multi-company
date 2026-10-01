<?php

namespace App\Filament\Pages\Accounting;

use App\Filament\Support\AccountingAccess;
use App\Filament\Support\MenuFields;
use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Application\SettlementService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Domain\Models\SettlementBatch;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Payment\Application\PaymentMethods;
use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * Pencairan settlement & sisa piutangnya (ACC-12).
 *
 * Layar ini menjawab satu pertanyaan yang sebelumnya tidak bisa dijawab sistem sama sekali:
 * **berapa uang non-tunai kita yang masih ditahan penyedia pembayaran?** Selama pertanyaan itu
 * tidak terjawab, piutang settlement di Neraca hanya akan bertambah selamanya.
 *
 * @property-read Form $form
 */
class SettlementPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'Pencairan Settlement';

    protected static ?string $title = 'Pencairan Settlement';

    protected static ?string $slug = 'pembukuan/settlement';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'filament.accounting.settlement';

    #[Url(as: 'per')]
    public ?string $asOf = null;

    public static function canAccess(): bool
    {
        return AccountingAccess::canView();
    }

    public function mount(): void
    {
        $this->asOf ??= CarbonImmutable::now(config('app.display_timezone'))->format('Y-m-d');
        $this->form->fill(['asOf' => $this->asOf]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('asOf')->label('Sisa piutang per tanggal')->live()
                ->afterStateUpdated(fn () => $this->cache = null),
        ])->columns(2)->statePath('');
    }

    private ?ReportTable $cache = null;

    public function outstanding(): ReportTable
    {
        return $this->cache ??= app(SettlementService::class)
            ->outstanding(CarbonImmutable::parse($this->asOf ?? 'today')->startOfDay());
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('record')
                ->label('Catat pencairan')->icon('heroicon-o-plus')
                ->visible(fn () => AccountingAccess::canManage())
                ->modalHeading('Catat dana settlement yang masuk')
                ->modalDescription('Jurnalnya dibuat sebagai draft dan mengikuti alur pengajuan seperti jurnal lain.')
                ->modalSubmitActionLabel('Simpan pencairan')
                ->form([
                    Select::make('method')->label('Metode')->required()
                        ->options(collect(SettlementService::METHODS)
                            ->mapWithKeys(fn (string $m) => [$m => PaymentMethods::DEFAULTS[$m]['label']])->all()),
                    DatePicker::make('settled_on')->label('Tanggal dana masuk')->required()
                        ->default(fn () => CarbonImmutable::now(config('app.display_timezone'))->format('Y-m-d')),
                    TextInput::make('gross_amount')->label('Piutang yang dicairkan')->required()->numeric()->minValue(0.01)
                        ->helperText('Nilai piutang settlement yang dibersihkan oleh pencairan ini.'),
                    TextInput::make('fee_amount')->label('Potongan saat pencairan')->numeric()->minValue(0)->default('0')
                        ->live(onBlur: true)
                        ->helperText('Di luar MDR yang sudah diakui saat penjualan. Kosongkan bila tidak ada.'),
                    Select::make('bank_account_id')->label('Masuk ke akun')->required()->searchable()
                        ->options(fn () => self::accountOptions())
                        ->default(fn () => Account::query()->where('code', '1110')->value('id')),
                    Select::make('fee_account_id')->label('Potongan dibebankan ke')->searchable()
                        ->placeholder('Ikut pemetaan bawaan')
                        ->visible(fn (Get $get) => (float) ($get('fee_amount') ?? 0) > 0)
                        ->options(fn () => self::accountOptions()),
                    Select::make('outlet_id')->label('Outlet')->searchable()->placeholder('Gabungan semua outlet')
                        ->options(fn () => Outlet::query()->orderBy('name')->pluck('name', 'id')->all()),
                    TextInput::make('reference')->label('Nomor settlement penyedia')->maxLength(100)
                        ->helperText('Dipakai mencegah satu pencairan tercatat dua kali.'),
                    Textarea::make('note')->label('Catatan')->maxLength(300)->rows(2),
                ])
                ->action(function (array $data): void {
                    $user = AccountingAccess::user();
                    if (! $user instanceof User || ! AccountingAccess::canManage()) {
                        Notification::make()->danger()->title('Tidak berwenang')->send();

                        return;
                    }
                    try {
                        /** @var array<string, mixed> $data */
                        $batch = app(SettlementService::class)->record($data, $user);
                    } catch (AccountingException $e) {
                        Notification::make()->danger()->title('Tidak dapat dicatat')->body($e->getMessage())->send();

                        return;
                    }
                    $this->cache = null;
                    Notification::make()->success()
                        ->title('Pencairan dicatat')
                        ->body('Jurnal draft '.$batch->journal()->firstOrFail()->number.' menunggu diajukan dan diposting.')
                        ->send();
                }),
        ];
    }

    /** @return array<string, string> */
    private static function accountOptions(): array
    {
        return Account::query()->where('is_postable', true)->where('is_active', true)->orderBy('code')
            ->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SettlementBatch::query()->with(['journal:id,number,status', 'outlet:id,name']))
            ->heading('Riwayat pencairan')
            ->columns([
                TextColumn::make('settled_on')->label('Tanggal')->date('d M Y')->sortable(),
                TextColumn::make('method')->label('Metode')
                    ->formatStateUsing(fn (string $state) => PaymentMethods::DEFAULTS[$state]['label'] ?? $state),
                TextColumn::make('gross_amount')->label('Dicairkan')->alignEnd()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('fee_amount')->label('Potongan')->alignEnd()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('net_amount')->label('Masuk bank')->alignEnd()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('outlet.name')->label('Outlet')->placeholder('Semua outlet')->toggleable(),
                TextColumn::make('reference')->label('No. settlement')->placeholder('-')->toggleable(),
                TextColumn::make('journal.number')->label('Jurnal')->fontFamily('mono')->placeholder('-'),
                TextColumn::make('journal.status')->label('Status jurnal')->badge()
                    ->formatStateUsing(fn (?string $state) => $state === null ? '-' : (Journal::STATUS_LABEL[$state] ?? $state))
                    ->color(fn (?string $state) => match ($state) {
                        'posted' => 'success',
                        'submitted' => 'info',
                        default => 'warning',
                    }),
            ])
            ->defaultSort('settled_on', 'desc')
            ->emptyStateHeading('Belum ada pencairan dicatat')
            ->emptyStateDescription('Catat setiap kali dana kartu atau QRIS masuk ke rekening, supaya piutang settlement berkurang.');
    }
}
