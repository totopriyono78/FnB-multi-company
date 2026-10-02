<?php

namespace Database\Seeders;

use App\Filament\Demo\DemoAccounts;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Consolidation\Application\ConsolidationService;
use App\Modules\Consolidation\Application\GroupService;
use App\Modules\Consolidation\Domain\Models\ConsolidationAdjustment;
use App\Modules\Consolidation\Domain\Models\Group;
use App\Modules\Identity\Application\StaffManager;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\CompanyRegistrar;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\CompanyStatus;
use App\Modules\Tenancy\Domain\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Holding & konsolidasi untuk peragaan (Kelompok 8 Tahap 1).
 *
 * Yang dibuat adalah keadaan grup yang **tidak seragam**, karena justru di situ kertas kerja
 * konsolidasi memperlihatkan gunanya:
 *
 * - **Gamatechno Holding** — entitas holding. Punya bukunya sendiri: beban kantor pusat dan satu
 *   talangan ke villa. Talangan itulah yang menuntut eliminasi.
 * - **Gamatechno Group** — entitas F&B yang bukunya terisi otomatis dari POS.
 * - **Villa Merapi** — bukunya diisi MANUAL (jurnal), seperti kenyataan: sistemnya terpisah.
 * - **Gamatechno Retail** — **tidak diisi apa pun**, dan itu disengaja. Dasbor holding harus
 *   menandainya "Belum ada data", dan laporannya harus menyebutkan bahwa nol di kolomnya tidak
 *   berarti tidak ada kegiatan. Grup demo yang semua entitasnya rapi tidak pernah memperlihatkan
 *   peringatan yang paling penting di modul ini.
 */
class DemoHoldingSeeder extends Seeder
{
    public function run(): void
    {
        // Idempoten: seeder demo dijalankan berulang kali di basis data yang sama.
        if (app(TenantContext::class)->runAsSystem(fn () => Group::query()->withoutGlobalScopes()->exists())) {
            return;
        }

        $context = app(TenantContext::class);
        $operasional = $context->runAsSystem(fn () => Company::query()->withoutGlobalScopes()
            ->where('name', 'Gamatechno Group')->first());
        if ($operasional === null) {
            return;
        }

        $holdingOwner = $this->user('Hamzah Gamatechno', 'hamzah@gtgroup.test', '6281200001111');
        $holding = $this->company($holdingOwner, 'Gamatechno Holding', 'Yogyakarta', [
            'legal_name' => 'PT Gamatechno Investama',
            'npwp' => '098765432101000',
            'address' => 'Jl. Kaliurang Km 5,6 No. 21, Caturtunggal, Depok, Sleman',
            'province' => 'DI Yogyakarta',
            'postal_code' => '55281',
        ]);

        $villaOwner = $this->user('Ratna Puspita', 'ratna@gtgroup.test', '6281200002222');
        $villa = $this->company($villaOwner, 'Villa Merapi', 'Sleman', [
            'legal_name' => 'PT Villa Merapi Asri',
            'province' => 'DI Yogyakarta',
        ]);

        $retailOwner = $this->user('Bagas Prakoso', 'bagas@gtgroup.test', '6281200003333');
        $retail = $this->company($retailOwner, 'Gamatechno Retail', 'Yogyakarta', [
            'legal_name' => 'PT Gamatechno Niaga',
            'province' => 'DI Yogyakarta',
        ]);

        foreach ([$holding, $villa, $retail] as $company) {
            $context->runAsTenant($company->id, function (): void {
                if (Account::query()->doesntExist()) {
                    app(ChartOfAccounts::class)->installTemplate();
                }
            });
        }

        /* ---------- Orang: konsolidator di holding, finance di villa ---------- */
        $konsolidator = $this->staff($holding, $holdingOwner, 'Nadia Pramesti', 'nadia@gtgroup.test', ['consolidator']);
        $holdingFinance = $this->staff($holding, $holdingOwner, 'Gilang Saputra', 'gilang@gtgroup.test', ['finance']);
        $holdingFinance2 = $this->staff($holding, $holdingOwner, 'Tari Wulandari', 'tari@gtgroup.test', ['finance']);
        $villaFinance = $this->staff($villa, $villaOwner, 'Oka Mahendra', 'oka@gtgroup.test', ['finance']);
        $villaFinance2 = $this->staff($villa, $villaOwner, 'Sari Kirana', 'sari@gtgroup.test', ['finance']);

        $bulan = CarbonImmutable::now(config('app.display_timezone'))->startOfMonth();

        /* ---------- Buku holding: beban kantor pusat + talangan ke villa ---------- */
        if ($holdingFinance !== null && $holdingFinance2 !== null) {
            $this->journal($holding, $holdingFinance, $holdingFinance2, $bulan->addDays(2),
                'Beban kantor pusat bulan ini', [
                    ['6101', '18000000', '0'],
                    ['1110', '0', '18000000'],
                ]);
            // Talangan antar entitas: piutang di holding, hutang di villa. Inilah yang dieliminasi.
            $this->journal($holding, $holdingFinance, $holdingFinance2, $bulan->addDays(4),
                'Talangan operasional ke Villa Merapi', [
                    ['1210', '25000000', '0'],
                    ['1110', '0', '25000000'],
                ]);
        }

        /* ---------- Buku villa: diisi manual, termasuk sisi lain talangan ---------- */
        if ($villaFinance !== null && $villaFinance2 !== null) {
            $this->journal($villa, $villaFinance, $villaFinance2, $bulan->addDays(4),
                'Terima talangan dari holding', [
                    ['1110', '25000000', '0'],
                    ['2101', '0', '25000000'],
                ]);
            $this->journal($villa, $villaFinance, $villaFinance2, $bulan->addDays(6),
                'Pendapatan kamar & amenities (rekap manual)', [
                    ['1110', '47500000', '0'],
                    ['4103', '0', '47500000'],
                ]);
            $this->journal($villa, $villaFinance, $villaFinance2, $bulan->addDays(7),
                'Beban gaji & listrik villa', [
                    ['6101', '14000000', '0'],
                    ['6103', '5200000', '0'],
                    ['1110', '0', '19200000'],
                ]);
        }

        /* ---------- Grup, snapshot, dan satu ayat eliminasi ---------- */
        $context->runAsTenant($holding->id, function () use ($operasional, $villa, $retail, $bulan, $konsolidator): void {
            if ($konsolidator !== null) {
                // Dijalankan SEBAGAI konsolidator, bukan sebagai sistem: kalau peran itu kurang satu
                // izin, seeder-nya gagal di sini — jauh lebih baik daripada ketahuan oleh pengguna.
                auth()->setUser($konsolidator);
            }

            $groups = app(GroupService::class);
            $group = $groups->create([
                'code' => 'GT',
                'name' => 'Grup Gamatechno',
                'legal_name' => 'PT Gamatechno Investama',
                'notes' => 'F&B, villa, dan retail. Konsolidasi manajerial.',
            ]);
            foreach ([$operasional, $villa, $retail] as $member) {
                $groups->attach($group, $member->code);
            }

            $service = app(ConsolidationService::class);
            $run = $service->generate($service->openRun(
                $group, $bulan, $bulan->endOfMonth(), $bulan->translatedFormat('F Y')
            ));

            $utang = Account::query()->where('code', '2101')->value('id');
            $piutang = Account::query()->where('code', '1210')->value('id');
            if (is_string($utang) && is_string($piutang)) {
                $service->addAdjustment($run, [
                    'kind' => ConsolidationAdjustment::ELIMINATION,
                    // Hutang villa dihapus terhadap piutang holding: uangnya tidak pernah keluar dari grup.
                    'debit_account_id' => $utang,
                    'credit_account_id' => $piutang,
                    'amount' => '25000000',
                    'description' => 'Eliminasi talangan operasional antar entitas',
                    'counterparty_note' => 'Holding ↔ Villa Merapi',
                ]);
            }

            auth()->forgetUser();
        });
    }

    private function user(string $name, string $email, string $phone): User
    {
        return app(TenantContext::class)->runAsSystem(function () use ($name, $email, $phone): User {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $name, 'phone' => $phone, 'password' => DemoAccounts::PASSWORD,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user;
        });
    }

    /** @param  array<string, string|null>  $profile */
    private function company(User $owner, string $name, string $city, array $profile): Company
    {
        $existing = app(TenantContext::class)->runAsSystem(
            fn () => Company::query()->withoutGlobalScopes()->where('name', $name)->first()
        );
        if ($existing !== null) {
            return $existing;
        }

        $company = app(CompanyRegistrar::class)->register(
            ['name' => $name, 'city' => $city, 'email' => $owner->email], $owner, 'pro'
        );
        app(TenantContext::class)->runAsSystem(
            fn () => $company->forceFill(['status' => CompanyStatus::Active] + $profile)->save()
        );

        return $company->refresh();
    }

    /** @param  list<string>  $roles */
    private function staff(Company $company, User $owner, string $name, string $email, array $roles): ?User
    {
        return app(TenantContext::class)->runAsTenant($company->id, function () use ($owner, $name, $email, $roles): ?User {
            app(StaffManager::class)->create($owner, [
                'name' => $name,
                'email' => $email,
                'employee_code' => null,
                'roles' => $roles,
                'scopes' => ['outlets' => [], 'brands' => []],
                'pin' => null,
            ]);

            return app(TenantContext::class)->runAsSystem(fn () => User::query()->where('email', $email)->first());
        });
    }

    /**
     * Satu jurnal yang benar-benar diposting, lewat jalur normal: diajukan satu orang, diposting
     * orang lain. Menulis langsung ke tabel akan menghasilkan data demo yang tidak mungkin ada.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $lines  [kode akun, debit, kredit]
     */
    private function journal(Company $company, User $pengaju, User $pemosting, CarbonImmutable $date, string $description, array $lines): void
    {
        app(TenantContext::class)->runAsTenant($company->id, function () use ($pengaju, $pemosting, $date, $description, $lines): void {
            $resolved = [];
            foreach ($lines as [$code, $debit, $credit]) {
                $accountId = Account::query()->where('code', $code)->value('id');
                if (! is_string($accountId)) {
                    return;
                }
                $resolved[] = ['account_id' => $accountId, 'debit' => $debit, 'credit' => $credit];
            }

            $service = app(JournalService::class);
            $journal = $service->create([
                'journal_date' => $date->format('Y-m-d'),
                'description' => $description,
                'lines' => $resolved,
            ], $pengaju);
            $service->post($service->submit($journal, $pengaju)->refresh(), $pemosting);
        });
    }
}
