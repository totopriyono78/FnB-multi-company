<?php

namespace App\Filament\Pages\Treasury;

use App\Filament\Support\MenuFields;
use App\Filament\Support\TreasuryAccess;
use App\Modules\Accounting\Domain\Models\JournalLine;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Reporting\Export\ReportExporter;
use App\Modules\Treasury\Application\BankReconciliationService;
use App\Modules\Treasury\Application\TreasuryException;
use App\Modules\Treasury\Domain\Models\BankStatement;
use App\Modules\Treasury\Domain\Models\BankStatementLine;
use App\Modules\Treasury\Domain\Models\CashAccount;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Rekonsiliasi bank (CSH-04).
 *
 * Satu layar, satu periode: impor rekening koran, cocokkan, lalu kunci. Yang tidak cocok tetap
 * terlihat sampai seseorang menjelaskannya — itulah seluruh gunanya.
 *
 * @property-read Form $form
 */
class BankReconciliationPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $navigationGroup = 'Kas & Hutang';

    protected static ?string $navigationLabel = 'Rekonsiliasi Bank';

    protected static ?string $title = 'Rekonsiliasi Bank';

    protected static ?string $slug = 'kas/rekonsiliasi';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.treasury.reconciliation';

    #[Url(as: 'periode')]
    public ?string $statementId = null;

    public ?string $closingBalance = null;

    public static function canAccess(): bool
    {
        return TreasuryAccess::canView();
    }

    public function mount(): void
    {
        $this->statementId ??= (string) (BankStatement::query()->latest('period_end')->value('id') ?? '');
        $this->form->fill(['statementId' => $this->statementId]);
        $this->closingBalance = (string) ($this->statement()?->closing_balance ?: '');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('statementId')->label('Periode rekening koran')->live()
                ->options(fn () => BankStatement::query()->with('cashAccount:id,name,code')
                    ->orderByDesc('period_end')->get()
                    ->mapWithKeys(fn (BankStatement $s) => [$s->id => $s->cashAccount->name.' · '
                        .$s->period_start->translatedFormat('d M Y').' – '.$s->period_end->translatedFormat('d M Y')
                        .($s->isLocked() ? ' · terkunci' : '')])
                    ->all())
                ->afterStateUpdated(function (?string $state): void {
                    $this->statementId = $state;
                    $this->closingBalance = (string) ($this->statement()?->closing_balance ?: '');
                }),
        ])->columns(2)->statePath('');
    }

    public function statement(): ?BankStatement
    {
        if ($this->statementId === null || $this->statementId === '') {
            return null;
        }

        /** @var BankStatement|null $statement */
        $statement = BankStatement::query()->with('cashAccount.account')->find($this->statementId);

        return $statement;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, BankStatementLine> */
    public function lines(): \Illuminate\Database\Eloquent\Collection
    {
        $statement = $this->statement();

        return $statement === null
            ? new \Illuminate\Database\Eloquent\Collection
            : $statement->lines()->with('journalLine.journal:id,number,journal_date')->get();
    }

    /** @return Collection<int, JournalLine> */
    public function bookLines(): Collection
    {
        $statement = $this->statement();

        return $statement === null
            ? collect()
            : app(BankReconciliationService::class)->unmatchedBookLines($statement);
    }

    /**
     * Kandidat pasangan untuk satu baris, siap ditawarkan ke orang.
     *
     * @return array<string, string>
     */
    public function candidateOptions(string $lineId): array
    {
        $statement = $this->statement();
        if ($statement === null) {
            return [];
        }
        /** @var BankStatementLine|null $line */
        $line = $statement->lines()->whereKey($lineId)->first();
        if ($line === null) {
            return [];
        }

        return app(BankReconciliationService::class)->candidatesFor($statement, $line)
            ->mapWithKeys(fn (JournalLine $jl) => [$jl->id => $jl->journal->number
                .' · '.$jl->journal->journal_date->translatedFormat('d M Y')
                // Satu dari dua kolom pasti nol; yang ditampilkan adalah yang berisi.
                .' · '.MenuFields::rupiah(BigDecimal::of($jl->debit)->isPositive() ? (string) $jl->debit : (string) $jl->credit)
                .' · '.mb_substr($jl->journal->description, 0, 60)])
            ->all();
    }

    public function summary(): ?ReportTable
    {
        $statement = $this->statement();

        return $statement === null ? null : app(BankReconciliationService::class)->summary($statement);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Impor rekening koran')->icon('heroicon-o-arrow-up-tray')
                ->visible(fn () => TreasuryAccess::canReconcile())
                ->modalHeading('Impor mutasi rekening')
                ->modalDescription('Berkas CSV atau XLSX dengan kolom: tanggal, keterangan, referensi, debet, kredit. '
                    .'Baris yang tidak terbaca dilaporkan sekaligus, bukan menghentikan impornya.')
                ->modalSubmitActionLabel('Impor mutasi')
                ->form([
                    Select::make('cash_account_id')->label('Rekening')->required()->searchable()
                        ->options(fn () => CashAccount::query()->where('is_active', true)
                            ->where('kind', CashAccount::BANK)->orderBy('code')
                            ->get()->mapWithKeys(fn (CashAccount $c) => [$c->id => $c->label()])->all()),
                    TextInput::make('closing_balance')->label('Saldo akhir menurut rekening koran (Rp)')
                        ->numeric()->required()
                        ->helperText('Angka inilah yang dibandingkan dengan saldo buku kita.'),
                    FileUpload::make('file')->label('Berkas mutasi')->required()
                        ->acceptedFileTypes(['text/csv', 'text/plain',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->storeFiles(false),
                ])
                ->action(fn (array $data) => $this->import($data)),
            Action::make('autoMatch')
                ->label('Cocokkan otomatis')->icon('heroicon-o-sparkles')->color('gray')
                ->visible(fn () => TreasuryAccess::canReconcile() && $this->statement()?->isLocked() === false)
                ->action(fn () => $this->autoMatch()),
            Action::make('lock')
                ->label('Kunci rekonsiliasi')->icon('heroicon-o-lock-closed')->color('success')
                ->visible(fn () => TreasuryAccess::canReconcile() && $this->statement()?->isLocked() === false)
                ->requiresConfirmation()
                ->modalHeading('Kunci hasil rekonsiliasi')
                ->modalDescription('Setelah dikunci, pencocokan periode ini tidak dapat diubah lagi. '
                    .'Rekonsiliasi yang masih bisa disunting belakangan bukan kontrol, hanya catatan.')
                ->modalSubmitActionLabel('Kunci periode ini')
                ->action(fn () => $this->lock()),
            Action::make('xlsx')->label('Ekspor ringkasan')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn () => $this->statement() !== null)
                ->action(fn () => $this->export()),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function import(array $data): void
    {
        $user = TreasuryAccess::user();
        if ($user === null || ! TreasuryAccess::canReconcile()) {
            Notification::make()->danger()->title('Tidak berwenang')->send();

            return;
        }

        $file = $data['file'] ?? null;
        $cash = CashAccount::query()->find((string) ($data['cash_account_id'] ?? ''));
        if (! $file instanceof UploadedFile || $cash === null) {
            Notification::make()->danger()->title('Berkas atau rekening belum dipilih')->send();

            return;
        }

        try {
            $hasil = app(BankReconciliationService::class)
                ->import($cash, $file->getRealPath(), $file->getClientOriginalName(), $user);
        } catch (TreasuryException $e) {
            Notification::make()->danger()->title('Impor gagal')->body($e->getMessage())->send();

            return;
        }

        $statement = $hasil['statement'];
        $statement->forceFill(['closing_balance' => (string) ($data['closing_balance'] ?? '0')])->save();
        $this->statementId = $statement->id;
        $this->closingBalance = (string) $statement->closing_balance;
        $this->form->fill(['statementId' => $this->statementId]);

        $masalah = $hasil['problems'];
        Notification::make()->success()
            ->title($hasil['imported'].' baris mutasi diimpor')
            // Masalahnya disebut apa adanya, bukan disembunyikan di balik "berhasil".
            ->body($masalah === [] ? 'Semua baris terbaca.'
                : count($masalah).' baris tidak terbaca: '.implode(' ', array_slice($masalah, 0, 3)))
            ->persistent()
            ->send();
    }

    public function autoMatch(): void
    {
        $statement = $this->statement();
        $user = TreasuryAccess::user();
        if ($statement === null || $user === null || ! TreasuryAccess::canReconcile()) {
            Notification::make()->danger()->title('Tidak berwenang')->send();

            return;
        }
        try {
            $cocok = app(BankReconciliationService::class)->autoMatch($statement, $user);
        } catch (TreasuryException $e) {
            Notification::make()->danger()->title('Tidak dapat diproses')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title($cocok.' baris tercocokkan otomatis')
            ->body('Sisanya punya lebih dari satu kemungkinan pasangan, atau memang tidak ada di buku — '
                .'keduanya perlu dilihat orang.')
            ->send();
    }

    public function matchLine(string $lineId, string $journalLineId): void
    {
        $this->guarded(function (BankStatement $statement, User $user) use ($lineId, $journalLineId): void {
            $line = $statement->lines()->whereKey($lineId)->firstOrFail();
            app(BankReconciliationService::class)->match($statement, $line, $journalLineId, $user);
        }, 'Baris dicocokkan.');
    }

    public function unmatchLine(string $lineId): void
    {
        $this->guarded(function (BankStatement $statement, User $user) use ($lineId): void {
            $line = $statement->lines()->whereKey($lineId)->firstOrFail();
            app(BankReconciliationService::class)->unmatch($statement, $line, $user);
        }, 'Pencocokan dibatalkan.');
    }

    public function ignoreLine(string $lineId, string $reason): void
    {
        $this->guarded(function (BankStatement $statement, User $user) use ($lineId, $reason): void {
            $line = $statement->lines()->whereKey($lineId)->firstOrFail();
            app(BankReconciliationService::class)->ignore($statement, $line, $reason, $user);
        }, 'Baris ditandai tidak perlu dicocokkan.');
    }

    public function saveClosingBalance(): void
    {
        $this->guarded(function (BankStatement $statement): void {
            $statement->forceFill(['closing_balance' => (string) ($this->closingBalance ?? '0')])->save();
        }, 'Saldo rekening koran disimpan.');
    }

    public function lock(): void
    {
        $this->guarded(function (BankStatement $statement, User $user): void {
            app(BankReconciliationService::class)->lock($statement, $user);
        }, 'Rekonsiliasi dikunci.');
    }

    /** @param  callable(BankStatement, User): void  $do */
    private function guarded(callable $do, string $sukses): void
    {
        $statement = $this->statement();
        $user = TreasuryAccess::user();
        if ($statement === null || $user === null || ! TreasuryAccess::canReconcile()) {
            Notification::make()->danger()->title('Tidak berwenang')->send();

            return;
        }
        try {
            $do($statement, $user);
        } catch (TreasuryException $e) {
            Notification::make()->danger()->title('Tidak dapat diproses')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title($sukses)->send();
    }

    public function export(): ?BinaryFileResponse
    {
        $table = $this->summary();
        if ($table === null) {
            return null;
        }
        $company = Filament::getTenant();
        $name = is_object($company) && property_exists($company, 'name') ? (string) $company->name : 'Perusahaan';

        $file = app(ReportExporter::class)->export($table, 'xlsx', $name, (string) $this->statementId);
        app(AuditLogger::class)->log('bank_reconciliation.exported', $this->statement(), new: ['format' => 'xlsx']);

        return response()->download($file['path'], $file['filename'])->deleteFileAfterSend();
    }
}
