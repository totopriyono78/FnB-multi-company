<?php

namespace App\Filament\Pages;

use App\Filament\Support\SalesLabels;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Sales\Application\BusinessCalendar;
use App\Modules\Sales\Application\EndOfDayService;
use App\Modules\Sales\Application\SalesException;
use App\Modules\Sales\Domain\Models\BusinessDay;
use App\Modules\Tenancy\Application\WritableCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Tutup hari per outlet (FR-POS-05): pratinjau ringkasan, shift yang masih terbuka, lalu kunci hari.
 *
 * @property-read Form $form
 */
class EndOfDay extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?string $navigationLabel = 'Tutup Hari';

    protected static ?string $title = 'Tutup Hari';

    protected static ?string $slug = 'tutup-hari';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.end-of-day';

    #[Url(as: 'outlet')]
    public ?string $outletId = null;

    #[Url(as: 'tanggal')]
    public ?string $date = null;

    public static function canAccess(): bool
    {
        $user = SalesLabels::user();

        return $user !== null && ($user->can('pos.end_of_day') || SalesLabels::canView()) && self::outlets() !== [];
    }

    /** @return array<string, string> */
    public static function outlets(): array
    {
        $user = SalesLabels::user();
        if ($user === null) {
            return [];
        }

        return app(AccessScope::class)->applyToOutletQuery(Outlet::query()->where('is_active', true), $user)
            ->orderBy('name')->get(['id', 'name', 'code'])
            ->mapWithKeys(fn (Outlet $o) => [$o->id => "{$o->name} ({$o->code})"])->all();
    }

    public function mount(): void
    {
        $outlets = self::outlets();
        if ($this->outletId === null || ! array_key_exists($this->outletId, $outlets)) {
            $this->outletId = array_key_first($outlets);
        }
        $outlet = $this->outlet();
        if ($this->date === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            $this->date = $outlet === null ? now()->format('Y-m-d') : app(BusinessCalendar::class)->today($outlet)->format('Y-m-d');
        }
        $this->form->fill(['outletId' => $this->outletId, 'date' => $this->date]);
    }

    public function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Select::make('outletId')->label('Outlet')->options(fn () => self::outlets())->required()->live()
                ->afterStateUpdated(fn (?string $state) => $this->outletId = $state),
            DatePicker::make('date')->label('Hari bisnis')->required()->live()->maxDate(now()->addDay())
                ->afterStateUpdated(fn (?string $state) => $this->date = $state),
        ]);
    }

    public function outlet(): ?Outlet
    {
        if ($this->outletId === null || ! array_key_exists($this->outletId, self::outlets())) {
            return null;
        }

        return Outlet::query()->find($this->outletId);
    }

    /** @return array<string, mixed>|null */
    public function preview(): ?array
    {
        $outlet = $this->outlet();
        if ($outlet === null || $this->date === null) {
            return null;
        }
        $date = CarbonImmutable::parse($this->date, 'UTC');
        $preview = app(EndOfDayService::class)->preview($outlet, $date);
        $closed = BusinessDay::query()->where('outlet_id', $outlet->id)->where('business_date', $date->format('Y-m-d'))->first();
        $preview['closed_record'] = $closed;

        return $preview;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('close')
                ->label('Tutup hari')
                ->icon('heroicon-m-lock-closed')
                ->color('danger')
                ->visible(fn () => SalesLabels::user()?->can('pos.end_of_day') ?? false)
                ->disabled(function (): bool {
                    $preview = $this->preview();

                    return ! WritableCompany::allows() || $preview === null || $preview['closed'] || $preview['open_shifts'] !== [];
                })
                ->requiresConfirmation()
                ->modalHeading('Tutup hari bisnis?')
                ->modalDescription('Setelah ditutup, shift baru tidak dapat dibuka untuk hari ini dan status menu habis dikembalikan tersedia. Refund untuk hari ini hanya dapat disetujui manajer.')
                ->modalSubmitActionLabel('Ya, tutup hari')
                ->form([Textarea::make('note')->label('Catatan (opsional)')->maxLength(500)->rows(2)])
                ->action(function (array $data): void {
                    $outlet = $this->outlet();
                    $user = SalesLabels::user();
                    if ($outlet === null || $user === null || $this->date === null || ! WritableCompany::allows()) {
                        return;
                    }
                    try {
                        app(EndOfDayService::class)->close($outlet, CarbonImmutable::parse($this->date, 'UTC'), $user, $data['note'] ?? null);
                    } catch (SalesException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title('Hari bisnis ditutup.')->send();
                }),
        ];
    }
}
