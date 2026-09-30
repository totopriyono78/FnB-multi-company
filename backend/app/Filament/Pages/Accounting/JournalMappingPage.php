<?php

namespace App\Filament\Pages\Accounting;

use App\Filament\Support\AccountingAccess;
use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Application\JournalMap;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\JournalMapping;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Pemetaan kejadian penjualan → akun (ACC-10).
 *
 * Layar ini yang menghubungkan apa yang terjadi di kasir dengan tempatnya di buku besar. Dua
 * bagian: aturan bawaan untuk tiap jenis kejadian, dan pengecualian per kategori menu bagi yang
 * ingin memisahkan pendapatan makanan dari minuman.
 *
 * Kategori yang dibiarkan kosong bukan kategori yang terlewat — ia mengikuti aturan bawaan. Itu
 * dikatakan di layar supaya tidak ada yang merasa wajib mengisi semuanya.
 *
 * @property-read Form $form
 */
class JournalMappingPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'Pemetaan Akun';

    protected static ?string $title = 'Pemetaan Akun Jurnal Otomatis';

    protected static ?string $slug = 'pembukuan/pemetaan-akun';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.accounting.journal-mapping';

    /** @var array<string, string|null> kunci `slot` dan `kategori.{id}` */
    public array $data = [];

    public static function canAccess(): bool
    {
        return AccountingAccess::canView();
    }

    public function mount(): void
    {
        $this->form->fill($this->current());
    }

    public function form(Form $form): Form
    {
        $accounts = $this->accountOptions();

        $slots = [];
        foreach (JournalMap::slots() as $slot => $def) {
            $slots[] = Select::make(self::field($slot))
                ->label($def['label'])
                ->helperText($def['hint'])
                ->options($accounts)
                ->searchable()
                ->disabled(! AccountingAccess::canManage());
        }

        $categories = [];
        foreach ($this->categories() as $id => $name) {
            $categories[] = Select::make('kategori.'.$id)
                ->label($name)
                ->placeholder('Ikut aturan bawaan')
                ->options($accounts)
                ->searchable()
                ->disabled(! AccountingAccess::canManage());
        }

        return $form
            ->schema([
                Section::make('Aturan bawaan')
                    ->description('Dipakai untuk setiap transaksi, kecuali ada pengecualian di bawah.')
                    ->schema($slots)
                    ->columns(2),
                Section::make('Pendapatan per kategori menu')
                    ->description($categories === []
                        ? 'Belum ada kategori menu.'
                        : 'Kosongkan bila kategori itu cukup memakai akun pendapatan bawaan.')
                    ->schema($categories)
                    ->columns(2)
                    ->collapsed(),
            ])
            ->statePath('data');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('installDefaults')
                ->label('Isi dengan akun bawaan')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->visible(fn () => AccountingAccess::canManage())
                ->requiresConfirmation()
                ->modalDescription('Slot yang belum terisi akan diisi akun bawaan dari template. Pemetaan yang sudah Anda atur tidak diubah.')
                ->action(function (): void {
                    try {
                        $created = app(JournalMap::class)->installDefaults();
                    } catch (AccountingException $e) {
                        Notification::make()->danger()->title('Tidak dapat mengisi')->body($e->getMessage())->send();

                        return;
                    }
                    $this->form->fill($this->current());
                    Notification::make()->success()
                        ->title($created === 0 ? 'Semua slot sudah terpetakan' : "{$created} pemetaan ditambahkan")
                        ->send();
                }),
        ];
    }

    /**
     * Nama isian form untuk sebuah slot.
     *
     * Slot pembayaran bernama `payment.cash`, dan TITIK di nama isian dibaca Filament sebagai jalur
     * bersarang (`data.payment.cash`) — nilainya lalu dicari di tempat yang tidak pernah diisi, dan
     * layar menampilkan slot kosong padahal pemetaannya ada di basis data. Ditemukan lewat E2E
     * 30 Sep 2026; titiknya diganti agar nama isian tetap satu tingkat.
     */
    private static function field(string $slot): string
    {
        return str_replace('.', '__', $slot);
    }

    public function save(): void
    {
        if (! AccountingAccess::canManage()) {
            Notification::make()->danger()->title('Anda tidak berwenang mengubah pemetaan akun.')->send();

            return;
        }

        $state = $this->form->getState();
        $map = app(JournalMap::class);
        $before = $this->current();
        $changed = 0;

        try {
            foreach (JournalMap::slots() as $slot => $def) {
                $value = $state[self::field($slot)] ?? null;
                if ($value === null || $value === ($before[self::field($slot)] ?? null)) {
                    continue;
                }
                $map->set($slot, null, (string) $value);
                $changed++;
            }
            foreach ($this->categories() as $id => $name) {
                $value = $state['kategori'][$id] ?? null;
                $was = $before['kategori'][$id] ?? null;
                if ($value === $was) {
                    continue;
                }
                if ($value === null) {
                    // Dikosongkan = kembali mengikuti aturan bawaan, bukan "tanpa akun".
                    JournalMapping::query()->where('slot', JournalMap::REVENUE)->where('ref_id', $id)->delete();
                } else {
                    $map->set(JournalMap::REVENUE, (string) $id, (string) $value);
                }
                $changed++;
            }
        } catch (AccountingException $e) {
            Notification::make()->danger()->title('Pemetaan tidak disimpan')->body($e->getMessage())->send();
            $this->form->fill($this->current());

            return;
        }

        $this->form->fill($this->current());
        Notification::make()->success()
            ->title($changed === 0 ? 'Tidak ada perubahan' : 'Pemetaan akun disimpan')
            ->send();
    }

    /**
     * Slot yang belum punya akun. Jurnal penjualan harian gagal disusun selama daftar ini tidak kosong.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $current = $this->current();

        return array_values(array_map(
            fn (string $slot) => JournalMap::slots()[$slot]['label'],
            array_filter(array_keys(JournalMap::slots()), fn (string $slot) => ($current[self::field($slot)] ?? null) === null),
        ));
    }

    /** @return array<string, mixed> */
    private function current(): array
    {
        $rows = JournalMapping::query()->get();
        $state = [];
        foreach (array_keys(JournalMap::slots()) as $slot) {
            $state[self::field($slot)] = $rows->first(fn (JournalMapping $m) => $m->slot === $slot && $m->ref_id === null)?->account_id;
        }
        $state['kategori'] = [];
        foreach (array_keys($this->categories()) as $id) {
            $state['kategori'][$id] = $rows->first(fn (JournalMapping $m) => $m->slot === JournalMap::REVENUE && $m->ref_id === $id)?->account_id;
        }

        return $state;
    }

    /** @return array<string, string> */
    private function accountOptions(): array
    {
        return Account::query()
            ->where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Account $a) => [$a->id => $a->code.' — '.$a->name])
            ->all();
    }

    /** @return array<string, string> */
    private function categories(): array
    {
        return MenuCategory::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
