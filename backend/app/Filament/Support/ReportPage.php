<?php

namespace App\Filament\Support;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reporting\Application\ReportAccess;
use App\Modules\Reporting\Application\ReportCatalog;
use App\Modules\Reporting\Application\ReportFilter;
use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Reporting\Export\ReportExporter;
use App\Modules\Tenancy\Domain\Models\Company;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Halaman laporan back-office: filter periode/brand/outlet (+ pilihan tampilan), ringkasan, tabel, dan ekspor
 * Excel/PDF (FR-RPT-08). Angka berasal dari ReportTable yang sama dengan API dan ekspor.
 *
 * @property-read Form $form
 */
abstract class ReportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'Laporan';

    protected static string $view = 'filament.pages.report';

    #[Url(as: 'dari')]
    public ?string $from = null;

    #[Url(as: 'sampai')]
    public ?string $to = null;

    #[Url(as: 'brand')]
    public ?string $brandId = null;

    #[Url(as: 'outlet')]
    public ?string $outletId = null;

    #[Url(as: 'tampilan')]
    public ?string $variant = null;

    private ?ReportTable $tableCache = null;

    private bool $tableBuilt = false;

    /** Jenis akses laporan (ReportAccess::SALES / INVENTORY). */
    abstract protected static function kind(): string;

    /** Kunci laporan di ReportCatalog untuk pilihan tampilan aktif. */
    abstract protected function reportKey(): string;

    /** @return array<string, string> pilihan tampilan (kosong = tidak ada) */
    protected function variants(): array
    {
        return [];
    }

    protected function variantLabel(): string
    {
        return 'Tampilan';
    }

    /** Tampilan tambahan di bawah tabel (nama view Blade) atau null. */
    public function extraView(): ?string
    {
        return null;
    }

    /** Laporan hanya memakai tanggal akhir (posisi saat ini) bila true. */
    protected function usesPeriod(): bool
    {
        return true;
    }

    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function canAccess(): bool
    {
        $user = self::user();
        $access = app(ReportAccess::class);

        return $user !== null && $access->can($user, static::kind()) && $access->outletIds($user, static::kind()) !== [];
    }

    public function mount(): void
    {
        $user = self::user();
        abort_if($user === null, 403);
        $today = ReportAccess::today();
        $this->to = self::validDate($this->to) ?? $today->format('Y-m-d');
        $this->from = self::validDate($this->from) ?? CarbonImmutable::parse($this->to)->startOfMonth()->format('Y-m-d');
        if ($this->from > $this->to) {
            $this->from = $this->to;
        }
        $brands = $this->brandOptions();
        if ($this->brandId !== null && ! array_key_exists($this->brandId, $brands)) {
            $this->brandId = null;
        }
        if ($this->outletId !== null && ! array_key_exists($this->outletId, $this->outletOptions($this->brandId))) {
            $this->outletId = null;
        }
        $variants = $this->variants();
        if ($variants !== [] && ($this->variant === null || ! array_key_exists($this->variant, $variants))) {
            $this->variant = array_key_first($variants);
        }
        $this->form->fill([
            'from' => $this->from, 'to' => $this->to, 'brandId' => $this->brandId,
            'outletId' => $this->outletId, 'variant' => $this->variant,
        ]);
    }

    public function form(Form $form): Form
    {
        $fields = [];
        if ($this->variants() !== []) {
            $fields[] = Select::make('variant')->label($this->variantLabel())
                ->options(fn () => $this->variants())->required()->selectablePlaceholder(false)->live()
                ->afterStateUpdated(fn (?string $state) => $this->variant = $state);
        }
        $fields[] = DatePicker::make('from')->label('Dari tanggal')->required()->live()->native()
            ->hidden(fn () => ! $this->usesPeriod())
            ->afterStateUpdated(fn (?string $state) => $this->from = self::validDate($state) ?? $this->from);
        $fields[] = DatePicker::make('to')->label('Sampai tanggal')->required()->live()->native()
            ->hidden(fn () => ! $this->usesPeriod())
            ->afterStateUpdated(fn (?string $state) => $this->to = self::validDate($state) ?? $this->to);
        if (count($this->brandOptions()) > 1) {
            $fields[] = Select::make('brandId')->label('Brand')->placeholder('Semua brand')
                ->options(fn () => $this->brandOptions())->live()
                ->afterStateUpdated(function (?string $state, callable $set): void {
                    $this->brandId = $state;
                    if ($this->outletId !== null && ! array_key_exists($this->outletId, $this->outletOptions($state))) {
                        $this->outletId = null;
                        $set('outletId', null);
                    }
                });
        }
        $fields[] = Select::make('outletId')->label('Outlet')->placeholder('Semua outlet')
            ->options(fn (Get $get) => $this->outletOptions($get('brandId')))->live()
            ->afterStateUpdated(fn (?string $state) => $this->outletId = $state);

        return $form->columns(min(count($fields), 5))->schema($fields);
    }

    /** @return array<string, string> */
    public function brandOptions(): array
    {
        $user = self::user();

        return $user === null ? [] : app(ReportAccess::class)->brandOptions($user, static::kind());
    }

    /** @return array<string, string> */
    public function outletOptions(?string $brandId = null): array
    {
        $user = self::user();

        return $user === null ? [] : app(ReportAccess::class)->outletOptions($user, static::kind(), $brandId);
    }

    public function filterError(): ?string
    {
        try {
            $this->filter();

            return null;
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        } catch (ModelNotFoundException) {
            return 'Brand atau outlet tidak ditemukan dalam cakupan Anda.';
        }
    }

    public function filter(): ReportFilter
    {
        $user = self::user();
        abort_if($user === null, 403);

        return app(ReportAccess::class)->filter($user, static::kind(), [
            'date_from' => $this->usesPeriod() ? $this->from : $this->to,
            'date_to' => $this->to,
            'brand_id' => $this->brandId,
            'outlet_id' => $this->outletId,
        ]);
    }

    public function reportTable(): ?ReportTable
    {
        if ($this->tableBuilt) {
            return $this->tableCache;
        }
        $this->tableBuilt = true;
        if ($this->filterError() !== null) {
            return null;
        }

        return $this->tableCache = app(ReportCatalog::class)->build($this->reportKey(), $this->filter());
    }

    public function formatCell(string|int|null $value, string $type): string
    {
        return ReportTable::format($value, $type);
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('exportXlsx')->label('Excel (.xlsx)')->icon('heroicon-o-table-cells')
                    ->action(fn () => $this->export('xlsx')),
                Action::make('exportPdf')->label('PDF')->icon('heroicon-o-document-text')
                    ->action(fn () => $this->export('pdf')),
            ])->label('Ekspor')->icon('heroicon-o-arrow-down-tray')->button()->color('gray'),
        ];
    }

    public function export(string $format): ?BinaryFileResponse
    {
        if ($this->filterError() !== null) {
            Notification::make()->title('Periksa kembali filter laporan.')->body($this->filterError())->danger()->send();

            return null;
        }
        $filter = $this->filter();
        $table = app(ReportCatalog::class)->build($this->reportKey(), $filter);
        $tenant = Filament::getTenant();
        $company = $tenant instanceof Company ? $tenant->name : '';
        $file = app(ReportExporter::class)->export($table, $format, $company, $filter->from->format('Ymd').'-'.$filter->to->format('Ymd'));

        app(AuditLogger::class)->log('report.exported', null, new: [
            'report' => $this->reportKey(), 'format' => $format, 'rows' => count($table->rows),
        ] + $filter->toArray(), userId: self::user()?->id);

        return response()->download($file['path'], $file['filename'], ['Content-Type' => $file['mime']])->deleteFileAfterSend();
    }

    protected static function validDate(?string $value): ?string
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        return substr($value, 0, 10);
    }
}
