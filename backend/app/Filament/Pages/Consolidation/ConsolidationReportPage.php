<?php

namespace App\Filament\Pages\Consolidation;

use App\Filament\Pages\Accounting\AccountingReportPage;
use App\Filament\Support\ConsolidationAccess;
use App\Modules\Consolidation\Domain\Models\ConsolidationRun;
use App\Modules\Reporting\Application\ReportTable;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Livewire\Attributes\Url;

/**
 * Induk halaman laporan konsolidasi.
 *
 * Bedanya dengan laporan lain: **penyaringnya bukan rentang tanggal, melainkan proses konsolidasi.**
 * Angka laporan ini bukan hasil membaca buku besar saat halaman dibuka — ia hasil snapshot yang
 * ditarik pada satu waktu tertentu. Memberi halaman ini penyaring tanggal akan menyarankan sebaliknya,
 * dan laporan yang menyarankan dirinya lebih segar daripada kenyataannya adalah laporan yang
 * membuat orang mengambil keputusan dari angka basi tanpa tahu.
 */
abstract class ConsolidationReportPage extends AccountingReportPage
{
    protected static ?string $navigationGroup = 'Holding & Konsolidasi';

    #[Url(as: 'proses')]
    public ?string $runId = null;

    private ?ConsolidationRun $run = null;

    private bool $runResolved = false;

    public static function canAccess(): bool
    {
        return ConsolidationAccess::canView();
    }

    public function mount(): void
    {
        $this->runId ??= self::latestRunId();
        $this->syncPeriod();
        $this->form->fill($this->formState());
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('runId')
                ->label('Proses konsolidasi')
                ->options(self::runOptions())
                ->native(false)
                ->live()
                ->afterStateUpdated(function (): void {
                    $this->run = null;
                    $this->runResolved = false;
                    $this->syncPeriod();
                    $this->refreshTable();
                })
                ->helperText('Angka laporan ini berasal dari saldo yang ditarik pada proses yang dipilih, '
                    .'bukan dari buku besar saat halaman ini dibuka.'),
        ])->columns(2)->statePath('');
    }

    /** @return array<string, mixed> */
    protected function formState(): array
    {
        return ['runId' => $this->runId];
    }

    public function emptyHint(): string
    {
        return 'Belum ada proses konsolidasi yang saldonya sudah ditarik. Buat satu di layar '
            .'Proses Konsolidasi, lalu jalankan "Tarik saldo entitas".';
    }

    public function emptyRowsHint(): string
    {
        return 'Belum ada saldo pada proses ini. Jalankan "Tarik saldo entitas" lebih dulu.';
    }

    protected function build(CarbonImmutable $from, CarbonImmutable $to): ?ReportTable
    {
        $run = $this->run();

        return $run === null ? null : $this->buildFor($run);
    }

    abstract protected function buildFor(ConsolidationRun $run): ReportTable;

    protected function run(): ?ConsolidationRun
    {
        if ($this->runResolved) {
            return $this->run;
        }
        $this->runResolved = true;

        $this->run = $this->runId === null
            ? null
            : ConsolidationRun::query()->with('group')->whereKey($this->runId)->first();

        return $this->run;
    }

    /** Nama berkas ekspor memakai periode proses, bukan tanggal hari ini. */
    private function syncPeriod(): void
    {
        $run = $this->run();
        $now = CarbonImmutable::now(config('app.display_timezone'));
        $this->from = $run?->period_start->format('Y-m-d') ?? $now->startOfMonth()->format('Y-m-d');
        $this->to = $run?->period_end->format('Y-m-d') ?? $now->endOfMonth()->format('Y-m-d');
    }

    private static function latestRunId(): ?string
    {
        $id = ConsolidationRun::query()->orderByDesc('period_end')->orderByDesc('created_at')->value('id');

        return is_string($id) ? $id : null;
    }

    /** @return array<string, string> */
    private static function runOptions(): array
    {
        return ConsolidationRun::query()
            ->orderByDesc('period_end')
            ->get()
            ->mapWithKeys(fn (ConsolidationRun $run): array => [
                $run->id => $run->describe().' · '.(ConsolidationRun::STATUS_LABEL[$run->status] ?? $run->status),
            ])
            ->all();
    }
}
