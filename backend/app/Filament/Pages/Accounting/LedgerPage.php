<?php

namespace App\Filament\Pages\Accounting;

use App\Modules\Accounting\Application\GeneralLedger;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Reporting\Application\ReportTable;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Livewire\Attributes\Url;

/** Buku besar satu akun dengan saldo berjalan (ACC-08). */
class LedgerPage extends AccountingReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Buku Besar';

    protected static ?string $title = 'Buku Besar';

    protected static ?string $slug = 'pembukuan/buku-besar';

    protected static ?int $navigationSort = 4;

    #[Url(as: 'akun')]
    public ?string $accountId = null;

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('accountId')->label('Akun')->searchable()->live()
                ->afterStateUpdated(fn () => $this->refreshTable())
                ->placeholder('Pilih akun')
                ->options(fn () => Account::query()->where('is_postable', true)->orderBy('code')
                    ->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all()),
            DatePicker::make('from')->label('Dari tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
            DatePicker::make('to')->label('Sampai tanggal')->live()->afterStateUpdated(fn () => $this->refreshTable()),
        ])->columns(3)->statePath('');
    }

    /** @return array<string, mixed> */
    protected function formState(): array
    {
        return parent::formState() + ['accountId' => $this->accountId];
    }

    protected function build(CarbonImmutable $from, CarbonImmutable $to): ?ReportTable
    {
        if ($this->accountId === null || $this->accountId === '') {
            return null;
        }
        $account = Account::query()->find($this->accountId);

        return $account === null ? null : app(GeneralLedger::class)->ledger($account, $from, $to);
    }
}
