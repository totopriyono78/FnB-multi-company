<?php

use App\Filament\Pages\Consolidation\ConsolidatedBalanceSheetPage;
use App\Filament\Pages\Consolidation\ConsolidatedIncomeStatementPage;
use App\Filament\Pages\Consolidation\HoldingDashboardPage;
use App\Filament\Pages\Consolidation\WorksheetPage;
use App\Filament\Resources\ConsolidationRunResource\Pages\CreateConsolidationRun;
use App\Filament\Resources\ConsolidationRunResource\Pages\ListConsolidationRuns;
use App\Filament\Resources\ConsolidationRunResource\Pages\ViewConsolidationRun;
use App\Filament\Resources\ConsolidationRunResource\RelationManagers\AdjustmentsRelationManager;
use App\Filament\Resources\GroupResource\Pages\CreateGroup;
use App\Filament\Resources\GroupResource\Pages\ListGroups;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Consolidation\Application\ConsolidationService;
use App\Modules\Consolidation\Application\GroupService;
use App\Modules\Consolidation\Domain\Models\ConsolidationAdjustment;
use App\Modules\Consolidation\Domain\Models\ConsolidationRun;
use App\Modules\Consolidation\Domain\Models\Group;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * Layar holding & konsolidasi (Kelompok 8 Tahap 1).
 *
 * Diuji lewat Livewire, bukan hanya lewat layanannya — aturan yang lahir dari insiden layar
 * kredensial payment gateway (27 Sep 2026), ketika 24 uji lulus sementara form aslinya meledak
 * karena tidak satu pun uji melewati jalur yang dipakai manusia.
 */
beforeEach(function () {
    [$this->holding, $this->holdingOwner] = Factory::company('Holding Layar');
    [$this->anak, $this->anakOwner] = Factory::company('Kedai Layar Anak');
    [$this->biasa, $this->biasaOwner] = Factory::company('Entitas Tanpa Grup');

    foreach ([$this->holding, $this->anak, $this->biasa] as $company) {
        Factory::tenant($company, fn () => app(ChartOfAccounts::class)->installTemplate());
    }

    [$this->konsolidator] = Factory::staff($this->holding, ['consolidator'], []);
    [$this->financeHolding] = Factory::staff($this->holding, ['finance'], []);
    [$this->kasir] = Factory::staff($this->holding, ['cashier'], []);
    [$this->financeBiasa] = Factory::staff($this->biasa, ['finance'], []);

    $this->base = "/admin/{$this->holding->code}";
});

afterEach(fn () => app(TenantContext::class)->reset());

function masukKonsolidasi(object $test, User $user, ?Company $company = null): void
{
    $company ??= $test->holding;
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $test->actingAs($user, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($company);
    app(TenantContext::class)->setTenant($company->id);
}

/** Grup dengan satu anak usaha, satu proses konsolidasi yang sudah ditarik saldonya. */
function grupSiap(object $test): ConsolidationRun
{
    [$pengaju] = Factory::staff($test->anak, ['finance'], []);
    [$pemosting] = Factory::staff($test->anak, ['finance'], []);
    $bulan = CarbonImmutable::parse('first day of this month');

    Factory::tenant($test->anak, function () use ($pengaju, $pemosting, $bulan): void {
        $service = app(JournalService::class);
        $kas = Account::query()->where('code', '1101')->firstOrFail();
        $jual = Account::query()->where('code', '4101')->firstOrFail();
        $journal = $service->create([
            'journal_date' => $bulan->addDay()->format('Y-m-d'),
            'description' => 'Penjualan tunai',
            'lines' => [
                ['account_id' => $kas->id, 'debit' => '7500000', 'credit' => '0'],
                ['account_id' => $jual->id, 'debit' => '0', 'credit' => '7500000'],
            ],
        ], $pengaju);
        $service->post($service->submit($journal, $pengaju)->refresh(), $pemosting);
    });

    return Factory::tenant($test->holding, function () use ($test, $bulan): ConsolidationRun {
        $groups = app(GroupService::class);
        $group = $groups->create(['code' => 'LYR', 'name' => 'Grup Layar']);
        $groups->attach($group, $test->anak->code);

        $service = app(ConsolidationService::class);

        return $service->generate($service->openRun($group, $bulan, $bulan->endOfMonth(), 'Bulan ini'));
    });
}

describe('siapa boleh membuka', function () {
    it('membuka seluruh layar konsolidasi untuk konsolidator di entitas holding', function () {
        grupSiap($this);
        masukKonsolidasi($this, $this->konsolidator);

        foreach ([
            '/konsolidasi/dasbor',
            '/konsolidasi/kertas-kerja',
            '/konsolidasi/neraca',
            '/konsolidasi/laba-rugi',
            '/konsolidasi/proses',
            '/konsolidasi/grup',
        ] as $path) {
            $this->get($this->base.$path)->assertOk();
        }
    });

    it('menutup layar konsolidasi bagi kasir', function () {
        grupSiap($this);
        masukKonsolidasi($this, $this->kasir);

        $this->get($this->base.'/konsolidasi/kertas-kerja')->assertForbidden();
        $this->get($this->base.'/konsolidasi/proses')->assertForbidden();
    });

    it('menutup layar konsolidasi di entitas yang tidak memegang grup', function () {
        grupSiap($this);
        masukKonsolidasi($this, $this->financeBiasa, $this->biasa);

        // Finance punya izin consolidation.*, tetapi entitasnya bukan holding: tidak ada grup
        // yang terlihat dari sana, jadi layarnya memang tidak punya apa pun untuk ditampilkan.
        $this->get("/admin/{$this->biasa->code}/konsolidasi/kertas-kerja")->assertForbidden();
    });

    it('tetap membuka layar grup di entitas tanpa grup, supaya grupnya bisa dibuat', function () {
        masukKonsolidasi($this, $this->financeBiasa, $this->biasa);

        $this->get("/admin/{$this->biasa->code}/konsolidasi/grup")->assertOk();
    });
});

describe('layar grup', function () {
    it('membuat grup lewat form dan menjadikan entitas holding anggota pertamanya', function () {
        masukKonsolidasi($this, $this->financeHolding);

        Livewire::test(CreateGroup::class)
            ->fillForm(['code' => 'BARU', 'name' => 'Grup Baru', 'legal_name' => 'PT Grup Baru'])
            ->call('create')
            ->assertHasNoFormErrors();

        $group = Factory::tenant($this->holding, fn () => Group::query()->where('code', 'BARU')->first());
        expect($group)->not->toBeNull();

        $codes = array_column(Factory::tenant($this->holding, fn () => app(GroupService::class)->members($group)), 'code');
        expect($codes)->toContain($this->holding->code);
    });

    it('menutup layar buat grup ketika entitas sudah memegang satu', function () {
        grupSiap($this);
        masukKonsolidasi($this, $this->financeHolding);

        // Dicegah di pintu, bukan di validasi form: grup kedua tidak punya arti sama sekali, jadi
        // formnya sebaiknya tidak pernah terbuka alih-alih terbuka lalu menolak isian yang sudah diketik.
        $this->get($this->base.'/konsolidasi/grup/baru')->assertForbidden();

        $jumlah = Factory::tenant($this->holding, fn () => Group::query()->count());
        expect($jumlah)->toBe(1);
    });

    it('menampilkan grup di daftar', function () {
        grupSiap($this);
        masukKonsolidasi($this, $this->konsolidator);

        Livewire::test(ListGroups::class)->assertCanSeeTableRecords(
            Factory::tenant($this->holding, fn () => Group::query()->get())
        );
    });
});

describe('layar proses konsolidasi', function () {
    it('membuat periode baru lewat form', function () {
        grupSiap($this);
        masukKonsolidasi($this, $this->konsolidator);
        $bulanLalu = CarbonImmutable::parse('first day of last month');

        Livewire::test(CreateConsolidationRun::class)
            ->fillForm([
                'period_start' => $bulanLalu->format('Y-m-d'),
                'period_end' => $bulanLalu->endOfMonth()->format('Y-m-d'),
                'label' => 'Bulan lalu',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $jumlah = Factory::tenant($this->holding, fn () => ConsolidationRun::query()->count());
        expect($jumlah)->toBe(2);
    });

    it('menarik saldo entitas dari tombol di daftar', function () {
        $run = grupSiap($this);
        Factory::tenant($this->holding, fn () => ConsolidationRun::query()->whereKey($run->id)
            ->update(['generated_at' => null, 'entity_count' => 0]));
        masukKonsolidasi($this, $this->konsolidator);

        Livewire::test(ListConsolidationRuns::class)
            ->callTableAction('generate', $run->id)
            ->assertHasNoTableActionErrors();

        $segar = Factory::tenant($this->holding, fn () => ConsolidationRun::query()->findOrFail($run->id));
        expect($segar->generated_at)->not->toBeNull()->and($segar->entity_count)->toBe(2);
    });

    it('mengunci final lalu menolak tarik ulang', function () {
        $run = grupSiap($this);
        masukKonsolidasi($this, $this->konsolidator);

        Livewire::test(ListConsolidationRuns::class)->callTableAction('finalize', $run->id);

        $segar = Factory::tenant($this->holding, fn () => ConsolidationRun::query()->findOrFail($run->id));
        expect($segar->status)->toBe(ConsolidationRun::FINAL);

        // Tombol tarik saldo memang hilang setelah final — itu yang menjaga angka yang sudah dikunci.
        Livewire::test(ListConsolidationRuns::class)->assertTableActionHidden('generate', $run->id);
    });

    it('menuntut alasan saat membuka kembali', function () {
        $run = grupSiap($this);
        masukKonsolidasi($this, $this->konsolidator);
        Livewire::test(ListConsolidationRuns::class)->callTableAction('finalize', $run->id);

        Livewire::test(ListConsolidationRuns::class)
            ->callTableAction('reopen', $run->id, ['reason' => 'ok'])
            ->assertHasTableActionErrors(['reason']);

        $segar = Factory::tenant($this->holding, fn () => ConsolidationRun::query()->findOrFail($run->id));
        expect($segar->status)->toBe(ConsolidationRun::FINAL);
    });
});

describe('layar laporan', function () {
    it('menampilkan kertas kerja dengan satu kolom per entitas', function () {
        $run = grupSiap($this);
        masukKonsolidasi($this, $this->konsolidator);

        Livewire::test(WorksheetPage::class)
            ->assertSet('runId', $run->id)
            ->assertSee('Kertas Kerja Konsolidasi')
            ->assertSee($this->anak->name)
            ->assertSee('PEMERIKSAAN');
    });

    it('menampilkan neraca dan laba rugi konsolidasi', function () {
        grupSiap($this);
        masukKonsolidasi($this, $this->konsolidator);

        Livewire::test(ConsolidatedBalanceSheetPage::class)
            ->assertSee('Neraca Konsolidasi')
            ->assertDontSee('aset tidak sama dengan liabilitas');

        Livewire::test(ConsolidatedIncomeStatementPage::class)
            ->assertSee('Laba Rugi Konsolidasi')
            ->assertSee('MANAJERIAL');
    });

    it('menandai entitas tanpa data di dasbor holding', function () {
        grupSiap($this);
        masukKonsolidasi($this, $this->konsolidator);

        // Entitas holding sendiri belum punya jurnal apa pun pada data uji ini.
        Livewire::test(HoldingDashboardPage::class)
            ->assertSee('Dasbor Holding')
            ->assertSee('Belum ada data');
    });

    it('mengekspor kertas kerja ke Excel', function () {
        grupSiap($this);
        masukKonsolidasi($this, $this->konsolidator);

        Livewire::test(WorksheetPage::class)->call('export', 'xlsx')->assertOk();
    });
});

describe('layar eliminasi', function () {
    it('menyimpan ayat berpasangan lewat form', function () {
        $run = grupSiap($this);
        masukKonsolidasi($this, $this->konsolidator);

        [$debit, $kredit] = Factory::tenant($this->holding, fn (): array => [
            (string) Account::query()->where('code', '2101')->value('id'),
            (string) Account::query()->where('code', '1210')->value('id'),
        ]);

        Livewire::test(AdjustmentsRelationManager::class, [
            'ownerRecord' => Factory::tenant($this->holding, fn () => ConsolidationRun::query()->findOrFail($run->id)),
            'pageClass' => ViewConsolidationRun::class,
        ])
            ->callTableAction('create', data: [
                'kind' => ConsolidationAdjustment::ELIMINATION,
                'amount' => '5000000',
                'debit_account_id' => $debit,
                'credit_account_id' => $kredit,
                'description' => 'Eliminasi talangan antar entitas',
            ])
            ->assertHasNoTableActionErrors();

        $ayat = Factory::tenant($this->holding, fn () => ConsolidationAdjustment::query()->where('run_id', $run->id)->get());
        expect($ayat)->toHaveCount(1)
            ->and((string) $ayat->first()->amount)->toBe('5000000.00');
    });
});
