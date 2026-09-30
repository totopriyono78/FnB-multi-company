<?php

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\FinancialStatements;
use App\Modules\Accounting\Application\GeneralLedger;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Tenancy\Application\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Tests\Support\Factory;

/**
 * Laba Rugi & Neraca (FIN-01, FIN-02).
 *
 * Satu janji menaungi seluruh berkas ini: **neraca yang dibentuk dari jurnal seimbang tidak boleh
 * bisa timpang**. Kalau ia timpang, yang salah bukan angkanya melainkan cara laporan ini menyusun
 * angkanya — dan itu tidak boleh sampai ke tangan orang yang memakai laporannya untuk mengambil
 * keputusan.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->awal = CarbonImmutable::parse('2026-10-01');
    $this->akhir = CarbonImmutable::parse('2026-10-31');
    dalamLaporan($this, fn () => app(ChartOfAccounts::class)->installTemplate());
});

afterEach(fn () => app(TenantContext::class)->reset());

function dalamLaporan(object $test, Closure $fn): mixed
{
    return Factory::tenant($test->company, $fn);
}

function akunLaporan(string $code): Account
{
    return Account::query()->where('code', $code)->firstOrFail();
}

/**
 * Posting satu jurnal dari pasangan [kode akun, debit, kredit].
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $baris
 */
function postingLaporan(object $test, array $baris, string $tanggal, string $keterangan = 'Uji laporan'): void
{
    dalamLaporan($test, function () use ($test, $baris, $tanggal, $keterangan): void {
        $service = app(JournalService::class);
        $jurnal = $service->create([
            'journal_date' => $tanggal,
            'description' => $keterangan,
            'lines' => array_map(fn (array $b) => [
                'account_id' => akunLaporan($b[0])->id, 'debit' => $b[1], 'credit' => $b[2],
            ], $baris),
        ], $test->owner);
        $service->post($jurnal, $test->owner);
    });
}

/** @return array<string, array{amount: string, previous: string|null, style: string}> baris laporan menurut namanya */
function barisLaporan(ReportTable $table): array
{
    $out = [];
    foreach ($table->rows as $row) {
        $out[(string) $row['name']] = [
            'amount' => (string) $row['amount'],
            'previous' => $row['previous'] ?? null,
            'style' => (string) $row['_style'],
        ];
    }

    return $out;
}

/** Skenario dasar: modal masuk, penjualan tunai berdiskon kena PB1, beban gaji dibayar. */
function skenarioSebulan(object $test): void
{
    postingLaporan($test, [['1110', '20000000', '0'], ['3101', '0', '20000000']], '2026-10-01', 'Setoran modal');
    postingLaporan($test, [
        ['1101', '9500000', '0'],      // kas masuk
        ['4301', '1000000', '0'],      // diskon penjualan (akun lawan pendapatan)
        ['4101', '0', '9545455'],      // penjualan makanan
        ['2201', '0', '954545'],       // PB1 terutang
    ], '2026-10-10', 'Penjualan harian');
    postingLaporan($test, [['5101', '3000000', '0'], ['1301', '0', '3000000']], '2026-10-12', 'Pemakaian bahan baku');
    postingLaporan($test, [['6101', '2500000', '0'], ['1110', '0', '2500000']], '2026-10-25', 'Gaji bulanan');
}

it('menyusun laba rugi dengan akun lawan yang benar-benar mengurangi pendapatan', function () {
    skenarioSebulan($this);

    $laporan = dalamLaporan($this, fn () => app(FinancialStatements::class)->incomeStatement($this->awal, $this->akhir));
    $baris = barisLaporan($laporan);

    expect($baris['4101 — Penjualan Makanan']['amount'])->toBe('9545455.00')
        // Diskon bersaldo debit meski jenisnya pendapatan: di laporan ia harus NEGATIF.
        ->and($baris['4301 — Diskon Penjualan']['amount'])->toBe('-1000000.00')
        ->and($baris['Jumlah Pendapatan']['amount'])->toBe('8545455.00')
        ->and($baris['Jumlah Harga Pokok Penjualan']['amount'])->toBe('3000000.00')
        ->and($baris['LABA KOTOR']['amount'])->toBe('5545455.00')
        ->and($baris['Jumlah Beban Operasional']['amount'])->toBe('2500000.00')
        ->and($baris['LABA (RUGI) BERSIH']['amount'])->toBe('3045455.00');

    // PB1 terutang adalah kewajiban, bukan pendapatan — ia tidak boleh muncul di laba rugi.
    expect($baris)->not->toHaveKey('2201 — PB1 / Pajak Restoran Terutang');

    $ringkasan = collect($laporan->summary)->keyBy('label');
    expect($ringkasan['Laba (Rugi) Bersih']['value'])->toBe('3045455.00')
        ->and($ringkasan['Marjin Bersih']['value'])->toBe('35.64');
});

it('menyusun neraca yang seimbang, dengan laba berjalan sebagai baris ekuitas yang dihitung', function () {
    skenarioSebulan($this);

    $neraca = dalamLaporan($this, fn () => app(FinancialStatements::class)->balanceSheet($this->awal->subDay(), $this->akhir));
    $baris = barisLaporan($neraca);

    // Aset: bank 20jt − gaji 2,5jt = 17,5jt · kas 9,5jt · persediaan −3jt
    expect($baris['JUMLAH ASET']['amount'])->toBe('24000000.00')
        ->and($baris['Jumlah Liabilitas']['amount'])->toBe('954545.00')
        ->and($baris['Laba (Rugi) Berjalan']['amount'])->toBe('3045455.00')
        ->and($baris['Jumlah Ekuitas']['amount'])->toBe('23045455.00')
        ->and($baris['JUMLAH LIABILITAS & EKUITAS']['amount'])->toBe('24000000.00');

    // Janji utamanya, dinyatakan sekali lagi secara langsung.
    expect($baris['JUMLAH ASET']['amount'])->toBe($baris['JUMLAH LIABILITAS & EKUITAS']['amount']);
    expect($neraca->notes)->each->not->toContain('PERINGATAN');
});

it('neraca tetap seimbang untuk rangkaian jurnal acak', function () {
    // Bukan angka pilihan yang kebetulan cocok: 40 jurnal acak di seluruh kelompok akun.
    $kode = ['1101', '1110', '1301', '1501', '1590', '2101', '2201', '3101', '3202', '4101', '4102', '4301', '5101', '6101', '6105', '6199'];
    mt_srand(20261001);
    for ($i = 0; $i < 40; $i++) {
        $nilai = (string) mt_rand(1000, 5000000);
        $a = $kode[array_rand($kode)];
        $b = $kode[array_rand($kode)];
        if ($a === $b) {
            continue;
        }
        postingLaporan($this, [[$a, $nilai, '0'], [$b, '0', $nilai]], '2026-10-'.str_pad((string) mt_rand(1, 28), 2, '0', STR_PAD_LEFT));
    }

    $neraca = dalamLaporan($this, fn () => app(FinancialStatements::class)->balanceSheet($this->awal->subDay(), $this->akhir));
    $baris = barisLaporan($neraca);

    expect($baris['JUMLAH ASET']['amount'])->toBe($baris['JUMLAH LIABILITAS & EKUITAS']['amount']);
    foreach ($neraca->notes as $note) {
        expect($note)->not->toContain('PERINGATAN');
    }
});

it('kolom pembanding menampilkan posisi sebelum periode berjalan', function () {
    postingLaporan($this, [['1110', '5000000', '0'], ['3101', '0', '5000000']], '2026-09-20', 'Modal awal');
    skenarioSebulan($this);

    $neraca = dalamLaporan($this, fn () => app(FinancialStatements::class)->balanceSheet($this->awal->subDay(), $this->akhir));
    $baris = barisLaporan($neraca);

    // Pembandingnya 30 Sep: hanya modal awal yang sudah ada.
    expect($baris['3101 — Modal Disetor']['previous'])->toBe('5000000.00')
        ->and($baris['3101 — Modal Disetor']['amount'])->toBe('25000000.00')
        ->and($baris['Laba (Rugi) Berjalan']['previous'])->toBe('0.00');

    expect($neraca->columns['previous']['label'])->toBe('Per 30 Sep 2026');
});

it('tidak menghitung jurnal draft, sama seperti buku besar', function () {
    skenarioSebulan($this);
    // Draft sebesar seluruh modal: kalau ikut terhitung, angkanya akan sangat berbeda.
    dalamLaporan($this, fn () => app(JournalService::class)->create([
        'journal_date' => '2026-10-15',
        'description' => 'Draft yang belum disetujui siapa pun',
        'lines' => [
            ['account_id' => akunLaporan('1110')->id, 'debit' => '99000000', 'credit' => '0'],
            ['account_id' => akunLaporan('3101')->id, 'debit' => '0', 'credit' => '99000000'],
        ],
    ], $this->owner));

    $neraca = dalamLaporan($this, fn () => app(FinancialStatements::class)->balanceSheet($this->awal->subDay(), $this->akhir));
    expect(barisLaporan($neraca)['JUMLAH ASET']['amount'])->toBe('24000000.00');
});

it('angkanya sama dengan neraca saldo pada rentang yang sama', function () {
    skenarioSebulan($this);

    [$neracaSaldo, $labaRugi] = dalamLaporan($this, fn () => [
        app(GeneralLedger::class)->trialBalance($this->awal, $this->akhir),
        app(FinancialStatements::class)->incomeStatement($this->awal, $this->akhir),
    ]);

    // Laba bersih = (kredit − debit) seluruh akun pendapatan, HPP, dan beban di neraca saldo.
    $laba = BigDecimal::zero();
    foreach ($neracaSaldo->rows as $row) {
        if (in_array($row['type'], ['Pendapatan', 'Harga Pokok Penjualan', 'Beban'], true)) {
            $laba = $laba->plus($row['credit'])->minus($row['debit']);
        }
    }

    expect((string) $laba->toScale(2))->toBe(barisLaporan($labaRugi)['LABA (RUGI) BERSIH']['amount']);
});

it('tidak membocorkan angka entitas lain', function () {
    skenarioSebulan($this);

    [$lain, $pemilikLain] = Factory::company('Warung Bu Ratna');
    Factory::tenant($lain, function (): void {
        app(ChartOfAccounts::class)->installTemplate();
        $neraca = app(FinancialStatements::class)->balanceSheet($this->awal->subDay(), $this->akhir);
        expect($neraca->rows)->not->toBeEmpty(); // seksi tetap ada
        foreach ($neraca->rows as $row) {
            expect($row['amount'] === null || $row['amount'] === '0.00')->toBeTrue("baris {$row['name']} membocorkan angka");
        }
    });
});
