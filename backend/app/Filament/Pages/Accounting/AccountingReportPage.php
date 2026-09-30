<?php

namespace App\Filament\Pages\Accounting;

use App\Filament\Support\AccountingAccess;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Reporting\Export\ReportExporter;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Induk halaman laporan akuntansi (neraca saldo, buku besar).
 *
 * Angkanya berbentuk `ReportTable` — bentuk yang sama dipakai layar, Excel, dan PDF (ADR 0006),
 * sehingga berkas ekspor tidak mungkin berbeda dari yang dibaca di layar.
 *
 * @property-read Form $form
 */
abstract class AccountingReportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'Akuntansi';

    protected static string $view = 'filament.accounting.report';

    #[Url(as: 'dari')]
    public ?string $from = null;

    #[Url(as: 'sampai')]
    public ?string $to = null;

    private ?ReportTable $cache = null;

    public static function canAccess(): bool
    {
        return AccountingAccess::canView();
    }

    public function mount(): void
    {
        $now = CarbonImmutable::now(config('app.display_timezone'));
        $this->from ??= $now->startOfMonth()->format('Y-m-d');
        $this->to ??= $now->endOfMonth()->format('Y-m-d');
        $this->form->fill($this->formState());
    }

    /** @return array<string, mixed> */
    protected function formState(): array
    {
        return ['from' => $this->from, 'to' => $this->to];
    }

    abstract protected function build(CarbonImmutable $from, CarbonImmutable $to): ?ReportTable;

    public function table(): ?ReportTable
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $from = CarbonImmutable::parse($this->from ?? 'today')->startOfDay();
        $to = CarbonImmutable::parse($this->to ?? 'today')->startOfDay();
        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        return $this->cache = $this->build($from, $to);
    }

    public function refreshTable(): void
    {
        $this->cache = null;
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('xlsx')->label('Excel (.xlsx)')->icon('heroicon-o-table-cells')
                    ->action(fn () => $this->export('xlsx')),
                Action::make('pdf')->label('PDF')->icon('heroicon-o-document-text')
                    ->action(fn () => $this->export('pdf')),
            ])->label('Ekspor')->icon('heroicon-o-arrow-down-tray')->button(),
        ];
    }

    public function export(string $format): ?BinaryFileResponse
    {
        $table = $this->table();
        if ($table === null || $table->rows === []) {
            Notification::make()->warning()->title('Tidak ada data untuk diekspor')->send();

            return null;
        }
        $company = Filament::getTenant();
        $name = is_object($company) && property_exists($company, 'name') ? (string) $company->name : 'Perusahaan';
        $slug = ($this->from ?? '').'_'.($this->to ?? '');

        $file = app(ReportExporter::class)->export($table, $format, $name, $slug);
        app(AuditLogger::class)->log('accounting_report.exported', null, new: [
            'report' => $table->key, 'format' => $format, 'from' => $this->from, 'to' => $this->to,
        ]);

        return response()->download($file['path'], $file['filename'])->deleteFileAfterSend();
    }
}
