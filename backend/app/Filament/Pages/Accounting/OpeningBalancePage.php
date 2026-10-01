<?php

namespace App\Filament\Pages\Accounting;

use App\Filament\Support\AccountingAccess;
use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Application\OpeningBalanceImporter;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Identity\Domain\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;

/**
 * Impor saldo awal (ACC-09).
 *
 * Layar ini sengaja dua langkah: **periksa dulu, baru tulis**. Saldo awal yang salah membuat setiap
 * laporan sesudahnya ikut salah, dan impor yang berhenti di tengah meninggalkan buku besar setengah
 * terisi — keadaan yang lebih sulit diperbaiki daripada belum mengimpor sama sekali.
 *
 * @property-read Form $form
 */
class OpeningBalancePage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'Saldo Awal';

    protected static ?string $title = 'Impor Saldo Awal';

    protected static ?string $slug = 'pembukuan/saldo-awal';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.accounting.opening-balance';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array{rows: list<array<string, string>>, problems: list<string>, debit: string, credit: string}|null */
    public ?array $preview = null;

    public static function canAccess(): bool
    {
        return AccountingAccess::canView();
    }

    public function mount(): void
    {
        $this->form->fill([
            'date' => CarbonImmutable::now(config('app.display_timezone'))->subDay()->format('Y-m-d'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('date')->label('Saldo awal per tanggal')->required()
                ->helperText('Biasanya hari terakhir sebelum sistem ini mulai dipakai.'),
            FileUpload::make('file')->label('Berkas saldo awal')->required()
                ->acceptedFileTypes([
                    'text/csv', 'text/plain', 'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])
                ->helperText('CSV atau XLSX dengan kolom: kode akun, debit, kredit.')
                // Berkasnya tidak perlu disimpan: ia hanya dibaca sekali lalu jadi jurnal.
                ->storeFiles(false),
        ])->columns(2)->statePath('data');
    }

    public function check(): void
    {
        $file = $this->form->getState()['file'] ?? null;
        $file = is_array($file) ? reset($file) : $file;
        if (! $file instanceof UploadedFile) {
            Notification::make()->warning()->title('Pilih berkasnya lebih dulu')->send();

            return;
        }

        $this->preview = app(OpeningBalanceImporter::class)->preview($file);

        if ($this->preview['problems'] !== []) {
            Notification::make()->warning()
                ->title(count($this->preview['problems']).' hal perlu diperbaiki')
                ->body('Berkasnya belum diimpor. Perbaiki daftar di bawah lalu unggah lagi.')->send();

            return;
        }
        Notification::make()->success()
            ->title(count($this->preview['rows']).' baris siap diimpor')
            ->body('Periksa daftarnya, lalu tekan Impor.')->send();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Impor sebagai jurnal')->icon('heroicon-o-check')
                ->visible(fn () => AccountingAccess::canManage()
                    && $this->preview !== null && $this->preview['problems'] === [] && $this->preview['rows'] !== [])
                ->requiresConfirmation()
                ->modalHeading('Impor saldo awal')
                ->modalDescription('Jurnal draft dibuat dari daftar ini. Seperti jurnal lain, ia baru masuk buku besar setelah diajukan dan diposting orang kedua.')
                ->action(function (): void {
                    $user = AccountingAccess::user();
                    if (! $user instanceof User || ! AccountingAccess::canManage() || $this->preview === null) {
                        Notification::make()->danger()->title('Tidak berwenang')->send();

                        return;
                    }
                    try {
                        /** @var list<array{code: string, debit: string, credit: string}> $rows */
                        $rows = $this->preview['rows'];
                        $journal = app(OpeningBalanceImporter::class)->import(
                            $rows,
                            CarbonImmutable::parse((string) ($this->form->getState()['date'] ?? 'today')),
                            $user,
                        );
                    } catch (AccountingException $e) {
                        Notification::make()->danger()->title('Tidak dapat diimpor')->body($e->getMessage())->send();

                        return;
                    }
                    $this->preview = null;
                    Notification::make()->success()->title('Saldo awal diimpor')
                        ->body('Jurnal '.$journal->number.' dibuat sebagai draft.')->send();
                }),
        ];
    }

    /** Jurnal saldo awal yang sudah pernah dibuat — supaya tidak ada yang mengimpor dua kali. */
    public function existingJournal(): ?Journal
    {
        $date = $this->data['date'] ?? null;

        return is_string($date) && $date !== ''
            ? app(OpeningBalanceImporter::class)->existing(CarbonImmutable::parse($date))
            : null;
    }
}
