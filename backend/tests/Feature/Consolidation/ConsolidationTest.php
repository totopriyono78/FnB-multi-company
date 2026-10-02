<?php

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Consolidation\Application\ConsolidationException;
use App\Modules\Consolidation\Application\ConsolidationReports;
use App\Modules\Consolidation\Application\ConsolidationService;
use App\Modules\Consolidation\Application\GroupService;
use App\Modules\Consolidation\Application\HoldingDashboard;
use App\Modules\Consolidation\Domain\Models\ConsolidationBalance;
use App\Modules\Consolidation\Domain\Models\ConsolidationEntity;
use App\Modules\Consolidation\Domain\Models\ConsolidationRun;
use App\Modules\Consolidation\Domain\Models\Group;
use App\Modules\Identity\Application\PermissionRegistry;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Tests\Support\Factory;

/**
 * Holding & konsolidasi manajerial (GRP-01, GRP-02, CON-01, CON-02, CON-05 manual, CON-06, CON-07, CON-09).
 *
 * Satu janji menaungi seluruh berkas ini: **entitas holding tidak pernah bisa membaca transaksi anak
 * usaha.** Angka grup sampai ke holding hanya sebagai saldo per akun, lewat satu proses batch, dan
 * sisanya ditolak PostgreSQL. Beberapa test di bawah ada semata-mata untuk membuktikan itu masih
 * benar setelah kode berubah.
 */
beforeEach(function () {
    [$this->holding, $this->holdingOwner] = Factory::company('Holding Selaras');
    [$this->anakA, $this->ownerA] = Factory::company('Kedai Kaliurang');
    [$this->anakB, $this->ownerB] = Factory::company('Villa Merapi');
    [$this->luar, $this->ownerLuar] = Factory::company('Pelanggan Lain');

    foreach ([$this->holding, $this->anakA, $this->anakB, $this->luar] as $company) {
        Factory::tenant($company, fn () => app(ChartOfAccounts::class)->installTemplate());
    }

    [$this->finance] = Factory::staff($this->holding, ['finance'], []);
    [$this->finance2] = Factory::staff($this->holding, ['finance'], []);
});

afterEach(fn () => app(TenantContext::class)->reset());

function diHolding(object $test, Closure $fn): mixed
{
    return Factory::tenant($test->holding, $fn);
}

/** Grup dengan holding + dua anak usaha sebagai anggota. */
function buatGrup(object $test): Group
{
    return diHolding($test, function () use ($test): Group {
        $groups = app(GroupService::class);
        $group = $groups->create(['code' => 'SLRS', 'name' => 'Grup Selaras']);
        $groups->attach($group, $test->anakA->code);
        $groups->attach($group, $test->anakB->code);

        return $group->refresh();
    });
}

/**
 * Satu jurnal terposting di satu entitas, lewat jalur normal (diajukan satu orang, diposting
 * orang lain) supaya maker–checker tidak dilewati dan jurnalnya benar-benar masuk buku besar.
 *
 * @param  list<array{code: string, debit?: string, credit?: string}>  $lines
 */
function jurnalTerposting(Company $company, CarbonImmutable $date, string $description, array $lines): void
{
    [$pengaju] = Factory::staff($company, ['finance'], []);
    [$pemosting] = Factory::staff($company, ['finance'], []);

    Factory::tenant($company, function () use ($date, $description, $lines, $pengaju, $pemosting): void {
        $service = app(JournalService::class);
        $resolved = [];
        foreach ($lines as $line) {
            $account = Account::query()->where('code', $line['code'])->firstOrFail();
            $resolved[] = [
                'account_id' => $account->id,
                'debit' => $line['debit'] ?? '0',
                'credit' => $line['credit'] ?? '0',
            ];
        }

        $journal = $service->create([
            'journal_date' => $date->format('Y-m-d'),
            'description' => $description,
            'lines' => $resolved,
        ], $pengaju);

        $service->post($service->submit($journal, $pengaju)->refresh(), $pemosting);
    });
}

describe('grup & keanggotaan (GRP-01)', function () {
    it('menjadikan entitas holding anggota grupnya sendiri', function () {
        $group = buatGrup($this);
        $codes = array_column(diHolding($this, fn () => app(GroupService::class)->members($group)), 'code');

        expect($codes)->toContain($this->holding->code, $this->anakA->code, $this->anakB->code);
    });

    it('menolak entitas yang sudah menjadi anggota grup lain', function () {
        $group = buatGrup($this);

        [$holding2] = Factory::company('Holding Kedua');
        $group2 = Factory::tenant($holding2, fn () => app(GroupService::class)
            ->create(['code' => 'DUA', 'name' => 'Grup Kedua']));

        Factory::tenant($holding2, fn () => app(GroupService::class)->attach($group2, $this->anakA->code));
    })->throws(ConsolidationException::class, 'sudah menjadi anggota grup lain');

    it('tidak mengizinkan entitas holding keluar dari grupnya sendiri', function () {
        $group = buatGrup($this);

        diHolding($this, fn () => app(GroupService::class)->detach($group, $this->holding->code));
    })->throws(ConsolidationException::class, 'tidak bisa dikeluarkan');

    it('mengeluarkan anggota dan membebaskannya untuk grup lain', function () {
        $group = buatGrup($this);
        diHolding($this, fn () => app(GroupService::class)->detach($group, $this->anakB->code));

        $groupId = Factory::system(fn () => Company::query()->whereKey($this->anakB->id)->value('group_id'));
        expect($groupId)->toBeNull();
    });

    it('menolak grup kedua untuk entitas holding yang sama', function () {
        buatGrup($this);
        diHolding($this, fn () => app(GroupService::class)->create(['code' => 'LAIN', 'name' => 'Grup Lain']));
    })->throws(ConsolidationException::class, 'sudah memegang satu grup');
});

describe('snapshot saldo (CON-01)', function () {
    beforeEach(function () {
        $this->group = buatGrup($this);
        $this->from = CarbonImmutable::parse('first day of this month');
        $this->to = CarbonImmutable::parse('last day of this month');

        // Anak A: penjualan tunai 10jt. Anak B: beban sewa 4jt dibayar bank.
        jurnalTerposting($this->anakA, $this->from->addDays(3), 'Penjualan tunai', [
            ['code' => '1101', 'debit' => '10000000'],
            ['code' => '4101', 'credit' => '10000000'],
        ]);
        jurnalTerposting($this->anakB, $this->from->addDays(5), 'Beban sewa', [
            ['code' => '6102', 'debit' => '4000000'],
            ['code' => '1102', 'credit' => '4000000'],
        ]);
    });

    it('menulis saldo tiap entitas ke tabel milik holding', function () {
        $run = diHolding($this, function (): ConsolidationRun {
            $service = app(ConsolidationService::class);

            return $service->generate($service->openRun($this->group, $this->from, $this->to, 'Bulan ini'));
        });

        expect($run->entity_count)->toBe(3);

        [$entities, $balances] = diHolding($this, fn (): array => [
            ConsolidationEntity::query()->where('run_id', $run->id)->pluck('source_name')->all(),
            ConsolidationBalance::query()->where('run_id', $run->id)->get(),
        ]);

        expect($entities)->toHaveCount(3);

        $penjualan = $balances->firstWhere('target_code', '4101');
        expect($penjualan)->not->toBeNull()
            // Pendapatan bersaldo kredit, jadi bertanda positif menurut kelompoknya.
            ->and(BigDecimal::of($penjualan->period)->toScale(2))->toEqual(BigDecimal::of('10000000.00'))
            ->and($penjualan->source_company_id)->toBe($this->anakA->id);

        $sewa = $balances->firstWhere('target_code', '6102');
        expect(BigDecimal::of($sewa->period)->toScale(2))->toEqual(BigDecimal::of('4000000.00'))
            ->and($sewa->source_company_id)->toBe($this->anakB->id);
    });

    it('bisa dijalankan ulang tanpa menggandakan apa pun', function () {
        $run = diHolding($this, function (): ConsolidationRun {
            $service = app(ConsolidationService::class);
            $run = $service->generate($service->openRun($this->group, $this->from, $this->to));

            return $service->generate($run->refresh());
        });

        [$entities, $balances] = diHolding($this, fn (): array => [
            ConsolidationEntity::query()->where('run_id', $run->id)->count(),
            ConsolidationBalance::query()->where('run_id', $run->id)
                ->where('target_code', '4101')->count(),
        ]);

        expect($entities)->toBe(3)->and($balances)->toBe(1);
    });

    it('memakai proses yang sama bila periodenya sama', function () {
        $pertama = diHolding($this, fn () => app(ConsolidationService::class)->openRun($this->group, $this->from, $this->to));
        $kedua = diHolding($this, fn () => app(ConsolidationService::class)->openRun($this->group, $this->from, $this->to));

        expect($kedua->id)->toBe($pertama->id);
    });

    it('tidak membawa satu baris jurnal pun ke entitas holding', function () {
        diHolding($this, function (): void {
            $service = app(ConsolidationService::class);
            $service->generate($service->openRun($this->group, $this->from, $this->to));
        });

        // Dari konteks holding, jurnal anak usaha tidak terlihat sama sekali — RLS yang menolaknya.
        $terlihat = diHolding($this, fn () => Journal::query()->count());
        expect($terlihat)->toBe(0);
    });
});

describe('laporan konsolidasi (CON-06, CON-07)', function () {
    beforeEach(function () {
        $this->group = buatGrup($this);
        $this->from = CarbonImmutable::parse('first day of this month');
        $this->to = CarbonImmutable::parse('last day of this month');

        jurnalTerposting($this->anakA, $this->from->addDay(), 'Penjualan tunai', [
            ['code' => '1101', 'debit' => '10000000'],
            ['code' => '4101', 'credit' => '10000000'],
        ]);
        jurnalTerposting($this->anakB, $this->from->addDays(2), 'Penjualan tunai', [
            ['code' => '1101', 'debit' => '6000000'],
            ['code' => '4101', 'credit' => '6000000'],
        ]);

        $this->run = diHolding($this, function (): ConsolidationRun {
            $service = app(ConsolidationService::class);

            return $service->generate($service->openRun($this->group, $this->from, $this->to, 'Bulan ini'));
        });
    });

    it('menjumlahkan pendapatan seluruh entitas di laba rugi konsolidasi', function () {
        $table = diHolding($this, fn () => app(ConsolidationReports::class)->incomeStatement($this->run));

        $pendapatan = collect($table->summary)->firstWhere('label', 'Pendapatan');
        expect(BigDecimal::of($pendapatan['value']))->toEqual(BigDecimal::of('16000000.00'));
    });

    it('menghasilkan neraca konsolidasi yang seimbang', function () {
        $table = diHolding($this, fn () => app(ConsolidationReports::class)->balanceSheet($this->run));

        expect(implode(' ', $table->notes))->not->toContain('aset tidak sama dengan liabilitas');
    });

    it('memberi satu kolom per entitas di kertas kerja, lalu jumlah, eliminasi, konsolidasi', function () {
        $table = diHolding($this, fn () => app(ConsolidationReports::class)->worksheet($this->run));

        expect(array_keys($table->columns))->toContain(
            'name',
            'e_'.$this->holding->code,
            'e_'.$this->anakA->code,
            'e_'.$this->anakB->code,
            'sum', 'elimination', 'consolidated',
        );
    });

    it('membuat baris pemeriksaan bernilai nol ketika seluruh buku seimbang', function () {
        $table = diHolding($this, fn () => app(ConsolidationReports::class)->worksheet($this->run));

        $periksa = collect($table->rows)->first(fn (array $row) => str_starts_with((string) $row['name'], 'PEMERIKSAAN'));
        expect($periksa)->not->toBeNull()
            ->and(BigDecimal::of((string) $periksa['consolidated'])->isZero())->toBeTrue();
    });

    it('menyebutkan bahwa konsolidasinya manajerial, bukan statutory', function () {
        $table = diHolding($this, fn () => app(ConsolidationReports::class)->incomeStatement($this->run));

        expect(implode(' ', $table->notes))->toContain('MANAJERIAL')->toContain('minoritas');
    });
});

describe('neraca konsolidasi dengan kegiatan sebelum periode', function () {
    /*
     * Ini regresi atas cacat nyata, bukan uji hipotetis.
     *
     * Versi pertama menyusun Neraca konsolidasi dari potongan waktu yang SAMA dengan Laba Rugi:
     * mutasi periode untuk akun laba rugi, saldo akhir untuk akun neraca. Selama seluruh entitas baru
     * mulai berjurnal bulan itu, neracanya seimbang dan seluruh uji lulus. Begitu satu entitas punya
     * kegiatan sebelum periode — yaitu keadaan setiap entitas yang sudah berjalan — asetnya memuat
     * laba sejak jurnal pertama sementara baris penyeimbangnya hanya memuat laba satu bulan, dan
     * neraca konsolidasinya timpang sebesar laba periode-periode sebelumnya.
     *
     * Yang menemukannya bukan uji, melainkan data demo. Uji ini ada supaya berikutnya ia tertangkap
     * di sini.
     */
    it('tetap seimbang ketika satu entitas sudah berjurnal sebelum periode', function () {
        $group = buatGrup($this);
        $from = CarbonImmutable::parse('first day of this month');
        $to = CarbonImmutable::parse('last day of this month');

        // Bulan lalu: penjualan di anak A. Bulan ini: penjualan lagi, lebih kecil.
        jurnalTerposting($this->anakA, $from->subMonth()->addDays(3), 'Penjualan bulan lalu', [
            ['code' => '1101', 'debit' => '9000000'],
            ['code' => '4101', 'credit' => '9000000'],
        ]);
        jurnalTerposting($this->anakA, $from->addDays(3), 'Penjualan bulan ini', [
            ['code' => '1101', 'debit' => '4000000'],
            ['code' => '4101', 'credit' => '4000000'],
        ]);

        $run = diHolding($this, function () use ($group, $from, $to): ConsolidationRun {
            $service = app(ConsolidationService::class);

            return $service->generate($service->openRun($group, $from, $to));
        });

        $neraca = diHolding($this, fn () => app(ConsolidationReports::class)->balanceSheet($run));
        expect(implode(' ', $neraca->notes))->not->toContain('aset tidak sama dengan liabilitas');

        // Laba Rugi tetap periode ini saja — 4jt, bukan 13jt.
        $laba = diHolding($this, fn () => app(ConsolidationReports::class)->incomeStatement($run));
        $pendapatan = collect($laba->summary)->firstWhere('label', 'Pendapatan');
        expect(BigDecimal::of($pendapatan['value']))->toEqual(BigDecimal::of('4000000.00'));

        // Dan kertas kerja memisahkan keduanya, bukan memilih satu lalu menyesatkan.
        $kertas = diHolding($this, fn () => app(ConsolidationReports::class)->worksheet($run));
        $periode = collect($kertas->rows)->first(fn (array $r) => $r['name'] === 'LABA (RUGI) PERIODE INI');
        $kumulatif = collect($kertas->rows)->first(fn (array $r) => str_starts_with((string) $r['name'], 'Laba (rugi) kumulatif'));
        $periksa = collect($kertas->rows)->first(fn (array $r) => str_starts_with((string) $r['name'], 'PEMERIKSAAN'));

        expect(BigDecimal::of((string) $periode['consolidated']))->toEqual(BigDecimal::of('4000000.00'))
            ->and(BigDecimal::of((string) $kumulatif['consolidated']))->toEqual(BigDecimal::of('13000000.00'))
            ->and(BigDecimal::of((string) $periksa['consolidated'])->isZero())->toBeTrue();
    });
});

describe('pemetaan akun (CON-02)', function () {
    it('menggabungkan akun dengan kode berbeda ke satu akun konsolidasi', function () {
        $group = buatGrup($this);
        $from = CarbonImmutable::parse('first day of this month');
        $to = CarbonImmutable::parse('last day of this month');

        // Villa mencatat pendapatannya di 4103 (Penjualan Lainnya); grup ingin melihatnya
        // menyatu dengan 4101 (Penjualan Makanan) milik entitas F&B.
        jurnalTerposting($this->anakA, $from->addDay(), 'Penjualan makanan', [
            ['code' => '1101', 'debit' => '3000000'],
            ['code' => '4101', 'credit' => '3000000'],
        ]);
        jurnalTerposting($this->anakB, $from->addDay(), 'Pendapatan kamar', [
            ['code' => '1101', 'debit' => '2000000'],
            ['code' => '4103', 'credit' => '2000000'],
        ]);

        $run = diHolding($this, function () use ($group, $from, $to): ConsolidationRun {
            $target = Account::query()->where('code', '4101')->firstOrFail();
            app(ConsolidationService::class)->map($group, [
                'source_code' => '4103',
                'account_id' => $target->id,
                'source_company_id' => $this->anakB->id,
                'note' => 'Villa memakai akun pendapatan lain-lain',
            ]);

            $service = app(ConsolidationService::class);

            return $service->generate($service->openRun($group, $from, $to));
        });

        $baris = diHolding($this, fn () => ConsolidationBalance::query()->where('run_id', $run->id)
            ->where('source_company_id', $this->anakB->id)->where('account_code', '4103')->first());

        expect($baris)->not->toBeNull()
            ->and($baris->target_code)->toBe('4101')
            ->and($baris->target_name)->toBe('Penjualan Makanan');

        // Dan di kertas kerja keduanya menjadi satu baris.
        $kertas = diHolding($this, fn () => app(ConsolidationReports::class)->worksheet($run));
        $nama = array_column($kertas->rows, 'name');
        expect($nama)->not->toContain('4103 — Penjualan Lainnya');

        $laba = diHolding($this, fn () => app(ConsolidationReports::class)->incomeStatement($run));
        $pendapatan = collect($laba->summary)->firstWhere('label', 'Pendapatan');
        expect(BigDecimal::of($pendapatan['value']))->toEqual(BigDecimal::of('5000000.00'));
    });
});

describe('eliminasi manual (CON-05)', function () {
    beforeEach(function () {
        $this->group = buatGrup($this);
        $this->from = CarbonImmutable::parse('first day of this month');
        $this->to = CarbonImmutable::parse('last day of this month');

        // Anak A menjual ke anak B: pendapatan di A, beban di B. Keduanya harus hilang saat dikonsolidasi.
        jurnalTerposting($this->anakA, $this->from->addDay(), 'Penjualan ke Villa', [
            ['code' => '1101', 'debit' => '2000000'],
            ['code' => '4101', 'credit' => '2000000'],
        ]);
        jurnalTerposting($this->anakB, $this->from->addDay(), 'Beli dari Kedai', [
            ['code' => '6102', 'debit' => '2000000'],
            ['code' => '1101', 'credit' => '2000000'],
        ]);

        $this->run = diHolding($this, function (): ConsolidationRun {
            $service = app(ConsolidationService::class);

            return $service->generate($service->openRun($this->group, $this->from, $this->to));
        });
    });

    it('menghapus pendapatan dan beban antar entitas dari laba rugi konsolidasi', function () {
        diHolding($this, function (): void {
            $pendapatan = Account::query()->where('code', '4101')->firstOrFail();
            $beban = Account::query()->where('code', '6102')->firstOrFail();

            app(ConsolidationService::class)->addAdjustment($this->run, [
                'kind' => 'elimination',
                // Mendebit pendapatan dan mengkredit beban: keduanya kembali ke nol.
                'debit_account_id' => $pendapatan->id,
                'credit_account_id' => $beban->id,
                'amount' => '2000000',
                'description' => 'Eliminasi penjualan antar entitas',
                'counterparty_note' => 'Kedai ↔ Villa',
            ]);
        });

        $table = diHolding($this, fn () => app(ConsolidationReports::class)->incomeStatement($this->run));
        $pendapatan = collect($table->summary)->firstWhere('label', 'Pendapatan');
        $bersih = collect($table->summary)->firstWhere('label', 'Laba (Rugi) Bersih');

        expect(BigDecimal::of($pendapatan['value'])->isZero())->toBeTrue()
            ->and(BigDecimal::of($bersih['value'])->isZero())->toBeTrue();
    });

    it('menolak ayat yang mendebit dan mengkredit akun yang sama', function () {
        diHolding($this, function (): void {
            $akun = Account::query()->where('code', '4101')->firstOrFail();
            app(ConsolidationService::class)->addAdjustment($this->run, [
                'debit_account_id' => $akun->id,
                'credit_account_id' => $akun->id,
                'amount' => '1000',
                'description' => 'Salah pilih',
            ]);
        });
    })->throws(ConsolidationException::class, 'tidak boleh sama');

    it('menolak ayat tanpa keterangan', function () {
        diHolding($this, function (): void {
            $debit = Account::query()->where('code', '4101')->firstOrFail();
            $credit = Account::query()->where('code', '6102')->firstOrFail();
            app(ConsolidationService::class)->addAdjustment($this->run, [
                'debit_account_id' => $debit->id,
                'credit_account_id' => $credit->id,
                'amount' => '1000',
                'description' => '  ',
            ]);
        });
    })->throws(ConsolidationException::class, 'Keterangan wajib diisi');

    it('tetap menyimpan eliminasi ketika saldo ditarik ulang', function () {
        diHolding($this, function (): void {
            $debit = Account::query()->where('code', '4101')->firstOrFail();
            $credit = Account::query()->where('code', '6102')->firstOrFail();
            $service = app(ConsolidationService::class);
            $service->addAdjustment($this->run, [
                'debit_account_id' => $debit->id,
                'credit_account_id' => $credit->id,
                'amount' => '2000000',
                'description' => 'Eliminasi penjualan antar entitas',
            ]);
            $service->generate($this->run->refresh());
        });

        $jumlah = diHolding($this, fn () => $this->run->adjustments()->count());
        expect($jumlah)->toBe(1);
    });
});

describe('penguncian final', function () {
    beforeEach(function () {
        $this->group = buatGrup($this);
        $this->from = CarbonImmutable::parse('first day of this month');
        $this->to = CarbonImmutable::parse('last day of this month');
        jurnalTerposting($this->anakA, $this->from->addDay(), 'Penjualan', [
            ['code' => '1101', 'debit' => '1000000'],
            ['code' => '4101', 'credit' => '1000000'],
        ]);
        $this->run = diHolding($this, function (): ConsolidationRun {
            $service = app(ConsolidationService::class);

            return $service->generate($service->openRun($this->group, $this->from, $this->to));
        });
    });

    it('menolak tarik ulang setelah final', function () {
        diHolding($this, function (): void {
            $service = app(ConsolidationService::class);
            $final = $service->finalize($this->run);
            $service->generate($final);
        });
    })->throws(ConsolidationException::class, 'sudah final');

    it('menolak final sebelum saldo pernah ditarik', function () {
        diHolding($this, function (): void {
            $service = app(ConsolidationService::class);
            $kosong = $service->openRun($this->group,
                $this->from->subMonth()->startOfMonth(), $this->from->subDay());
            $service->finalize($kosong);
        });
    })->throws(ConsolidationException::class, 'Jalankan tarik saldo lebih dulu');

    it('menuntut alasan saat dibuka kembali', function () {
        diHolding($this, function (): void {
            $service = app(ConsolidationService::class);
            $service->reopen($service->finalize($this->run), 'oke');
        });
    })->throws(ConsolidationException::class, 'Alasan membuka kembali wajib diisi');

    it('membuka kembali dengan alasan yang tercatat', function () {
        $run = diHolding($this, function (): ConsolidationRun {
            $service = app(ConsolidationService::class);

            return $service->reopen($service->finalize($this->run),
                'Anak usaha memposting jurnal koreksi sesudah penguncian.');
        });

        expect($run->status)->toBe(ConsolidationRun::DRAFT)->and($run->finalized_at)->toBeNull();
    });
});

describe('isolasi tenant', function () {
    it('menyembunyikan proses konsolidasi holding dari entitas lain', function () {
        $group = buatGrup($this);
        $from = CarbonImmutable::parse('first day of this month');
        $to = CarbonImmutable::parse('last day of this month');
        diHolding($this, function () use ($group, $from, $to): void {
            $service = app(ConsolidationService::class);
            $service->generate($service->openRun($group, $from, $to));
        });

        // Dari entitas di luar grup: tidak ada grup, tidak ada proses, tidak ada saldo.
        [$grup, $proses, $saldo] = Factory::tenant($this->luar, fn (): array => [
            Group::query()->count(),
            ConsolidationRun::query()->count(),
            ConsolidationBalance::query()->count(),
        ]);

        expect($grup)->toBe(0)->and($proses)->toBe(0)->and($saldo)->toBe(0);
    });

    it('menyembunyikan grup dari anak usaha anggotanya sendiri', function () {
        buatGrup($this);

        $terlihat = Factory::tenant($this->anakA, fn () => Group::query()->count());
        expect($terlihat)->toBe(0);
    });
});

describe('peran konsolidator (GRP-02)', function () {
    it('tidak memberi konsolidator satu pun izin ke data transaksi', function () {
        $izin = PermissionRegistry::defaultRoles()['consolidator']['permissions'];

        expect($izin)->toContain('consolidation.view', 'consolidation.manage')
            ->and($izin)->not->toContain('accounting.view')
            ->and($izin)->not->toContain('treasury.view')
            ->and($izin)->not->toContain('report.sales.company')
            ->and($izin)->not->toContain('payment.view');
    });

    it('tidak memberi pemilik kewenangan menjalankan konsolidasi sendiri', function () {
        $izin = PermissionRegistry::defaultRoles()['owner']['permissions'];

        expect($izin)->toContain('consolidation.view')->and($izin)->not->toContain('consolidation.manage');
    });
});

describe('dasbor holding (CON-09)', function () {
    it('menandai entitas yang belum punya jurnal terposting', function () {
        $group = buatGrup($this);
        $from = CarbonImmutable::parse('first day of this month');
        $to = CarbonImmutable::parse('last day of this month');
        jurnalTerposting($this->anakA, $from->addDay(), 'Penjualan', [
            ['code' => '1101', 'debit' => '1000000'],
            ['code' => '4101', 'credit' => '1000000'],
        ]);

        $table = diHolding($this, function () use ($group, $from, $to) {
            $service = app(ConsolidationService::class);
            $run = $service->generate($service->openRun($group, $from, $to));

            return app(HoldingDashboard::class)->build($run);
        });

        $status = collect($table->rows)->pluck('status', 'entity');
        expect($status->get($this->anakB->code.' — '.$this->anakB->name))->toBe('Belum ada data')
            ->and($status->get($this->anakA->code.' — '.$this->anakA->name))->toBe(HoldingDashboard::READY);
    });

    it('memberi tahu apa adanya ketika belum ada proses konsolidasi', function () {
        $table = diHolding($this, fn () => app(HoldingDashboard::class)->build(null));

        expect($table->rows)->toBe([])
            ->and(implode(' ', $table->notes))->toContain('Belum ada proses konsolidasi');
    });
});
