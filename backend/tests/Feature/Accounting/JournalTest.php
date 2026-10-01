<?php

use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\GeneralLedger;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Application\PeriodService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\AccountingPeriod;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Domain\Models\JournalLine;
use App\Modules\Tenancy\Application\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Factory;

/**
 * Inti akuntansi: bagan akun, jurnal, periode, buku besar (ACC-01, 04, 05, 07, 08).
 *
 * Yang dijaga di sini adalah janji-janji yang membuat sebuah buku besar layak dipercaya, bukan
 * sekadar "fiturnya jalan": debit selalu sama dengan kredit, jurnal yang sudah diposting tidak bisa
 * diubah oleh siapa pun termasuk kode aplikasi sendiri, periode tertutup benar-benar tertutup, dan
 * angka satu entitas tidak pernah terlihat dari entitas lain.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    /*
     * Pemeriksa adalah ORANG KEDUA (ACC-05). Sejak maker–checker berlaku, satu orang tidak bisa
     * mengajukan sekaligus memposting jurnal yang sama — jadi uji pun butuh dua orang, persis
     * seperti di lapangan.
     */
    [$this->pemeriksa] = Factory::staff($this->company, ['finance'], []);
    $this->tanggal = CarbonImmutable::parse('2026-10-05');
    dalamPembukuan($this, fn () => app(ChartOfAccounts::class)->installTemplate());
});

afterEach(fn () => app(TenantContext::class)->reset());

function dalamPembukuan(object $test, Closure $fn): mixed
{
    return Factory::tenant($test->company, $fn);
}

function akunPembukuan(string $code): Account
{
    return Account::query()->where('code', $code)->firstOrFail();
}

/** Jurnal kas masuk sederhana: kas bertambah, pendapatan bertambah. */
function jurnalKasSederhana(object $test, string $nominal = '100000', ?string $tanggal = null): array
{
    return [
        'journal_date' => $tanggal ?? $test->tanggal->format('Y-m-d'),
        'description' => 'Penjualan tunai harian',
        'lines' => [
            ['account_id' => akunPembukuan('1101')->id, 'debit' => $nominal, 'credit' => '0'],
            ['account_id' => akunPembukuan('4101')->id, 'debit' => '0', 'credit' => $nominal],
        ],
    ];
}

/** Buat → ajukan (pengaju) → posting (pemeriksa). Satu putaran maker–checker yang lengkap. */
function buatDanPostingJurnal(object $test, string $nominal = '100000', ?string $tanggal = null): Journal
{
    $service = app(JournalService::class);
    $jurnal = $service->create(jurnalKasSederhana($test, $nominal, $tanggal), $test->owner);
    $service->submit($jurnal, $test->owner);

    return $service->post($jurnal, $test->pemeriksa);
}

it('memasang bagan akun standar dan aman dijalankan ulang', function () {
    dalamPembukuan($this, function () {
        $jumlah = Account::query()->count();
        expect($jumlah)->toBeGreaterThan(40);

        // Akun yang diacu jurnal otomatis nanti harus ada sejak awal.
        foreach (['1101', '1201', '1202', '2201', '4101', '4201', '4301', '4303', '6105', '6106'] as $code) {
            expect(Account::query()->where('code', $code)->where('is_system', true)->exists())
                ->toBeTrue("akun sistem {$code} tidak ada di template");
        }
        // Akun induk tidak boleh dijurnal.
        expect(akunPembukuan('1000')->is_postable)->toBeFalse()
            ->and(akunPembukuan('1101')->is_postable)->toBeTrue();
        // Akun lawan: jenisnya pendapatan, saldo normalnya debit karena ia mengurangi pendapatan.
        expect(akunPembukuan('4301')->type)->toBe(Account::REVENUE)
            ->and(akunPembukuan('4301')->normal_balance)->toBe('debit');

        expect(app(ChartOfAccounts::class)->installTemplate())->toBe(0);
        expect(Account::query()->count())->toBe($jumlah);
    });
});

it('memposting jurnal yang seimbang dan menolak yang tidak seimbang', function () {
    dalamPembukuan($this, function () {
        $jurnal = buatDanPostingJurnal($this);

        expect($jurnal->status)->toBe(Journal::POSTED)
            ->and($jurnal->number)->toStartWith('JU-2610-')
            ->and($jurnal->submitted_by)->toBe($this->owner->id)
            ->and($jurnal->posted_by)->toBe($this->pemeriksa->id);

        $timpang = jurnalKasSederhana($this);
        $timpang['lines'][1]['credit'] = '90000';

        expect(fn () => app(JournalService::class)->create($timpang, $this->owner))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('JOURNAL_UNBALANCED'));
    });
});

it('menolak baris yang mengisi debit dan kredit sekaligus', function () {
    dalamPembukuan($this, function () {
        $data = jurnalKasSederhana($this);
        $data['lines'][0]['credit'] = '5000';

        expect(fn () => app(JournalService::class)->create($data, $this->owner))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('JOURNAL_LINE_SIDE'));
    });
});

it('menolak posting ke akun induk', function () {
    dalamPembukuan($this, function () {
        // Menjurnal ke akun induk membuat jumlah induk tidak lagi sama dengan jumlah anaknya.
        $data = jurnalKasSederhana($this);
        $data['lines'][0]['account_id'] = akunPembukuan('1000')->id;

        expect(fn () => app(JournalService::class)->create($data, $this->owner))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('ACCOUNT_NOT_POSTABLE'));
    });
});

it('menolak posting ke akun yang sudah dinonaktifkan', function () {
    dalamPembukuan($this, function () {
        $akun = akunPembukuan('4103');
        $akun->forceFill(['is_active' => false])->save();

        $data = jurnalKasSederhana($this);
        $data['lines'][1]['account_id'] = $akun->id;

        expect(fn () => app(JournalService::class)->create($data, $this->owner))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('ACCOUNT_INACTIVE'));
    });
});

it('tidak mengizinkan jurnal terposting diubah, bahkan langsung lewat basis data', function () {
    dalamPembukuan($this, function () {
        $jurnal = buatDanPostingJurnal($this);

        // Lewat layanan.
        expect(fn () => app(JournalService::class)->update($jurnal, ['description' => 'diubah diam-diam'], $this->owner))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('JOURNAL_NOT_DRAFT'));

        /*
         * Dan lewat kueri langsung. Ini yang membedakan penjaga sungguhan dari penjaga di kertas:
         * satu skrip perbaikan data yang ceroboh tidak boleh cukup untuk mengubah buku besar.
         *
         * Tiap percobaan dibungkus transaksi tersendiri: di Postgres, satu perintah yang gagal
         * membatalkan seluruh transaksi berjalan, sehingga tanpa savepoint uji ini akan mati di
         * percobaan pertama dan dua penjaga sisanya tidak pernah teruji.
         */
        $ditolak = fn (Closure $usaha) => expect(fn () => DB::transaction($usaha))
            ->toThrow(QueryException::class);

        $ditolak(fn () => DB::table('journals')->where('id', $jurnal->id)->update(['description' => 'diubah paksa']));
        $ditolak(fn () => DB::table('journal_lines')->where('journal_id', $jurnal->id)->update(['debit' => '1']));
        $ditolak(fn () => DB::table('journal_lines')->where('journal_id', $jurnal->id)->delete());

        expect(Journal::query()->findOrFail($jurnal->id)->description)->toBe('Penjualan tunai harian');
    });
});

it('mengoreksi lewat jurnal balik yang menihilkan jurnal asal', function () {
    dalamPembukuan($this, function () {
        $asli = buatDanPostingJurnal($this);

        $balik = app(JournalService::class)->reverse($asli, $this->owner, 'Salah akun pendapatan', $this->tanggal);

        /*
         * Pembalik lahir DIAJUKAN, bukan langsung diposting: membalik jurnal berarti mengubah angka
         * yang sudah terbit, jadi justru di sini pemeriksaan orang kedua paling dibutuhkan.
         * Selama pembaliknya belum diposting, jurnal asal masih berlaku — dan itulah keadaan
         * yang sebenarnya.
         */
        expect($balik->status)->toBe(Journal::SUBMITTED)
            ->and($balik->number)->toStartWith('JB-2610-')
            ->and($balik->reverses_journal_id)->toBe($asli->id)
            ->and($asli->refresh()->status)->toBe(Journal::POSTED);

        $balik = app(JournalService::class)->post($balik, $this->pemeriksa);

        $asli->refresh();
        expect($asli->status)->toBe(Journal::REVERSED)
            ->and($asli->reversed_by_journal_id)->toBe($balik->id);

        // Sisi jurnalnya benar-benar cermin.
        $asal = JournalLine::query()->where('journal_id', $asli->id)->orderBy('line_no')->get();
        $cermin = JournalLine::query()->where('journal_id', $balik->id)->orderBy('line_no')->get();
        expect((string) $cermin[0]->credit)->toBe((string) $asal[0]->debit)
            ->and((string) $cermin[1]->debit)->toBe((string) $asal[1]->credit);

        /*
         * Saldo akhirnya kembali nol — itu arti "dibalik" yang sebenarnya. Barisnya TETAP muncul
         * dengan mutasi dua arah, dan memang seharusnya begitu: neraca saldo melaporkan apa yang
         * terjadi, bukan menyembunyikan koreksi.
         */
        $neraca = app(GeneralLedger::class)->trialBalance($this->tanggal->startOfMonth(), $this->tanggal->endOfMonth());
        $kas = collect($neraca->rows)->firstWhere('code', '1101');
        expect($kas['debit'])->toBe('100000.00')
            ->and($kas['credit'])->toBe('100000.00')
            ->and($kas['closing_debit'])->toBe('0.00')
            ->and($kas['closing_credit'])->toBe('0.00')
            ->and($neraca->totals['closing_debit'])->toBe('0.00')
            ->and($neraca->totals['closing_credit'])->toBe('0.00');
    });
});

it('menolak membalik jurnal yang belum diposting atau sudah pernah dibalik', function () {
    dalamPembukuan($this, function () {
        $draft = app(JournalService::class)->create(jurnalKasSederhana($this), $this->owner);
        expect(fn () => app(JournalService::class)->reverse($draft, $this->owner, 'apa saja'))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('JOURNAL_NOT_POSTED'));

        // Pembalik yang masih menggantung pun menghalangi pembalik kedua.
        $asli = buatDanPostingJurnal($this);
        $balik = app(JournalService::class)->reverse($asli, $this->owner, 'Salah akun', $this->tanggal);
        expect(fn () => app(JournalService::class)->reverse($asli->refresh(), $this->owner, 'Dibalik lagi', $this->tanggal))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('REVERSAL_PENDING'));

        app(JournalService::class)->post($balik, $this->pemeriksa);
        expect(fn () => app(JournalService::class)->reverse($asli->refresh(), $this->owner, 'Dibalik lagi', $this->tanggal))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('JOURNAL_NOT_POSTED'));
    });
});

it('menolak posting ke periode yang sudah ditutup', function () {
    dalamPembukuan($this, function () {
        buatDanPostingJurnal($this);
        $periode = AccountingPeriod::query()->where('year', 2026)->where('month', 10)->firstOrFail();
        app(PeriodService::class)->close($periode, $this->owner, 'Tutup buku Oktober');

        expect(fn () => app(JournalService::class)->create(jurnalKasSederhana($this), $this->owner))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('PERIOD_CLOSED'));

        // Dibuka kembali harus selalu beralasan, karena ia mengubah laporan yang sudah terbit.
        expect(fn () => app(PeriodService::class)->reopen($periode->refresh(), $this->owner, ''))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('REOPEN_REASON_REQUIRED'));

        app(PeriodService::class)->reopen($periode->refresh(), $this->owner, 'Ada nota terlambat masuk');
        expect(app(JournalService::class)->create(jurnalKasSederhana($this), $this->owner)->status)->toBe(Journal::DRAFT);
    });
});

it('menolak menutup periode yang masih punya jurnal draft', function () {
    dalamPembukuan($this, function () {
        app(JournalService::class)->create(jurnalKasSederhana($this), $this->owner);
        $periode = AccountingPeriod::query()->where('month', 10)->firstOrFail();

        expect(fn () => app(PeriodService::class)->close($periode, $this->owner))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('PERIOD_HAS_DRAFTS'));
    });
});

it('menyusun neraca saldo yang selalu seimbang', function () {
    dalamPembukuan($this, function () {
        buatDanPostingJurnal($this, '100000');
        buatDanPostingJurnal($this, '250000');

        $neraca = app(GeneralLedger::class)->trialBalance($this->tanggal->startOfMonth(), $this->tanggal->endOfMonth());
        $totals = $neraca->totals;

        expect($totals['debit'])->toBe('350000.00')
            ->and($totals['credit'])->toBe('350000.00')
            // Janji terpenting sebuah neraca saldo.
            ->and($totals['closing_debit'])->toBe($totals['closing_credit'])
            ->and($neraca->notes)->not->toContain('PERINGATAN: total debit dan kredit tidak sama.');

        $kas = collect($neraca->rows)->firstWhere('code', '1101');
        $pendapatan = collect($neraca->rows)->firstWhere('code', '4101');
        expect($kas['closing_debit'])->toBe('350000.00')
            ->and($pendapatan['closing_credit'])->toBe('350000.00');
    });
});

it('tidak menghitung jurnal draft di buku besar', function () {
    dalamPembukuan($this, function () {
        app(JournalService::class)->create(jurnalKasSederhana($this, '999000'), $this->owner);

        $neraca = app(GeneralLedger::class)->trialBalance($this->tanggal->startOfMonth(), $this->tanggal->endOfMonth());

        // Buku besar yang memuat angka yang belum disetujui siapa pun bukan buku besar.
        expect($neraca->rows)->toBe([])
            ->and($neraca->totals['debit'])->toBe('0.00');
    });
});

it('membawa saldo awal dari periode sebelumnya', function () {
    dalamPembukuan($this, function () {
        buatDanPostingJurnal($this, '100000', '2026-09-20');
        buatDanPostingJurnal($this, '50000', '2026-10-05');

        $oktober = app(GeneralLedger::class)->trialBalance(
            CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
        $kas = collect($oktober->rows)->firstWhere('code', '1101');

        expect($kas['opening_debit'])->toBe('100000.00')
            ->and($kas['debit'])->toBe('50000.00')
            ->and($kas['closing_debit'])->toBe('150000.00');
    });
});

it('menyusun buku besar satu akun dengan saldo berjalan', function () {
    dalamPembukuan($this, function () {
        buatDanPostingJurnal($this, '100000', '2026-10-02');
        buatDanPostingJurnal($this, '40000', '2026-10-07');

        $bb = app(GeneralLedger::class)->ledger(akunPembukuan('1101'),
            CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

        // Baris pertama selalu saldo awal, lalu tiap mutasi dengan saldo berjalannya.
        expect($bb->rows[0]['description'])->toBe('Saldo awal')
            ->and($bb->rows[0]['balance'])->toBe('0.00')
            ->and($bb->rows[1]['balance'])->toBe('100000.00')
            ->and($bb->rows[2]['balance'])->toBe('140000.00')
            ->and($bb->totals['debit'])->toBe('140000.00');
    });
});

it('tidak mengizinkan akun yang sudah bermutasi berubah arti', function () {
    dalamPembukuan($this, function () {
        buatDanPostingJurnal($this);
        $kas = akunPembukuan('1101');

        expect(fn () => app(ChartOfAccounts::class)->update($kas, ['type' => Account::EXPENSE]))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('ACCOUNT_HAS_ENTRIES'));
        /*
         * Untuk penghapusan dipakai akun NON-sistem: pada akun bawaan, penjaga "tidak boleh dihapus"
         * bicara lebih dulu, sehingga penjaga "sudah bermutasi" tidak akan pernah teruji di sana.
         */
        $service = app(JournalService::class);
        $lain = $service->create([
            'journal_date' => $this->tanggal->format('Y-m-d'),
            'description' => 'Beban lain-lain dari kas kecil',
            'lines' => [
                ['account_id' => akunPembukuan('6199')->id, 'debit' => '25000', 'credit' => '0'],
                ['account_id' => akunPembukuan('1102')->id, 'debit' => '0', 'credit' => '25000'],
            ],
        ], $this->owner);
        $service->post($service->submit($lain, $this->owner), $this->pemeriksa);

        expect(fn () => app(ChartOfAccounts::class)->delete(akunPembukuan('6199')))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('ACCOUNT_HAS_ENTRIES'));

        // Namanya tetap boleh diperbaiki — yang dikunci artinya, bukan ejaannya.
        expect(app(ChartOfAccounts::class)->update($kas, ['name' => 'Kas Laci Kasir'])->name)->toBe('Kas Laci Kasir');
    });
});

it('tidak mengizinkan akun bawaan dihapus', function () {
    dalamPembukuan($this, function () {
        expect(fn () => app(ChartOfAccounts::class)->delete(akunPembukuan('2201')))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('ACCOUNT_IS_SYSTEM'));
    });
});

it('tidak membocorkan bagan akun dan jurnal antar company', function () {
    dalamPembukuan($this, fn () => buatDanPostingJurnal($this));

    [$tetangga] = Factory::company('Warung Seberang');

    Factory::tenant($tetangga, function () {
        // Entitas baru mulai dari nol: tidak satu akun pun, tidak satu jurnal pun.
        expect(Account::query()->count())->toBe(0)
            ->and(Journal::query()->count())->toBe(0)
            ->and(JournalLine::query()->count())->toBe(0);

        $neraca = app(GeneralLedger::class)->trialBalance(
            CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
        expect($neraca->rows)->toBe([])->and($neraca->totals['debit'])->toBe('0.00');
    });
});

it('memberi nomor jurnal berurutan per entitas per bulan', function () {
    dalamPembukuan($this, function () {
        $a = app(JournalService::class)->create(jurnalKasSederhana($this), $this->owner);
        $b = app(JournalService::class)->create(jurnalKasSederhana($this), $this->owner);
        $c = app(JournalService::class)->create(jurnalKasSederhana($this, '1000', '2026-11-03'), $this->owner);

        expect($a->number)->toBe('JU-2610-0001')
            ->and($b->number)->toBe('JU-2610-0002')
            // Bulan baru mulai dari satu lagi.
            ->and($c->number)->toBe('JU-2611-0001');
    });
});
