<?php

use App\Modules\Accounting\Application\CashFlowStatement;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\FinancialStatements;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Tenancy\Application\TenantContext;
use Carbon\CarbonImmutable;
use Tests\Support\Factory;

/**
 * Arus Kas (FIN-03) dan Perubahan Ekuitas (FIN-04).
 *
 * Satu janji yang menaungi keduanya: **laporannya harus menutup sendiri**. Arus kas yang jumlahnya
 * tidak sama dengan saldo kas di buku besar, dan perubahan ekuitas yang awal + laba + mutasinya
 * tidak sama dengan akhir, adalah laporan yang lebih buruk daripada tidak ada — ia terlihat benar.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kedai Arus Kas');
    $this->outlet = Factory::outlet($this->company, null, ['code' => 'ARK']);
    [$this->finance] = Factory::staff($this->company, ['finance'], []);
    [$this->finance2] = Factory::staff($this->company, ['finance'], []);

    Factory::tenant($this->company, fn () => app(ChartOfAccounts::class)->installTemplate());
});

afterEach(fn () => app(TenantContext::class)->reset());

/**
 * Jurnal yang langsung diposting lewat jalur normal (diajukan satu orang, diposting orang lain).
 *
 * @param  list<array{string, string, string}>  $baris  [kode akun, debit, kredit]
 */
function jurnalArus(object $test, string $tanggal, string $keterangan, array $baris): Journal
{
    return Factory::tenant($test->company, function () use ($test, $tanggal, $keterangan, $baris): Journal {
        $service = app(JournalService::class);
        $lines = [];
        foreach ($baris as [$kode, $debit, $kredit]) {
            $lines[] = [
                'account_id' => Account::query()->where('code', $kode)->value('id'),
                'debit' => $debit, 'credit' => $kredit, 'outlet_id' => $test->outlet->id,
            ];
        }
        $jurnal = $service->create([
            'journal_date' => $tanggal, 'description' => $keterangan, 'lines' => $lines,
        ], $test->finance);

        return $service->post($service->submit($jurnal, $test->finance)->refresh(), $test->finance2);
    });
}

it('menyusun arus kas yang jumlahnya sama dengan saldo kas di buku besar', function () {
    // Pendanaan: setoran modal pemilik ke bank.
    jurnalArus($this, '2026-10-02', 'Setoran modal pemilik', [
        ['1110', '50000000', '0'], ['3101', '0', '50000000'],
    ]);
    // Operasi: penjualan tunai.
    jurnalArus($this, '2026-10-05', 'Penjualan tunai', [
        ['1101', '12000000', '0'], ['4101', '0', '12000000'],
    ]);
    // Operasi: bayar beban listrik dari bank.
    jurnalArus($this, '2026-10-08', 'Bayar listrik', [
        ['6103', '3000000', '0'], ['1110', '0', '3000000'],
    ]);
    // Investasi: beli peralatan dari bank.
    jurnalArus($this, '2026-10-10', 'Beli mesin kopi', [
        ['1501', '20000000', '0'], ['1110', '0', '20000000'],
    ]);
    // Bukan arus kas sama sekali: setoran dari laci ke bank.
    jurnalArus($this, '2026-10-11', 'Setor hasil penjualan ke bank', [
        ['1110', '10000000', '0'], ['1101', '0', '10000000'],
    ]);

    $tabel = Factory::tenant($this->company, fn () => app(CashFlowStatement::class)
        ->build(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')));

    $baris = collect($tabel->rows)->keyBy('name');

    expect($baris->get('Arus kas bersih dari aktivitas operasi')['amount'])->toBe('9000000.00')
        ->and($baris->get('Arus kas bersih dari aktivitas investasi')['amount'])->toBe('-20000000.00')
        ->and($baris->get('Arus kas bersih dari aktivitas pendanaan')['amount'])->toBe('50000000.00')
        ->and($baris->get('KENAIKAN (PENURUNAN) KAS BERSIH')['amount'])->toBe('39000000.00')
        ->and($baris->get('KAS & SETARA KAS AKHIR PERIODE')['amount'])->toBe('39000000.00')
        // Tidak ada peringatan: laporannya menutup terhadap buku besar.
        ->and(implode(' ', $tabel->notes))->not->toContain('PERINGATAN');

    /*
     * Transfer laci → bank sama sekali tidak muncul sebagai arus kas. Memindahkan uang dari satu
     * tempat ke tempat lain bukan arus kas, dan laporan yang menghitungnya akan menggandakan
     * penerimaan setiap kali kasir menyetor.
     */
    expect(collect($tabel->rows)->pluck('name')->implode(' '))->not->toContain('1101');
});

it('menggolongkan penerimaan piutang sebagai operasi, bukan investasi', function () {
    // Tagihan terbit: belum ada kas sama sekali.
    jurnalArus($this, '2026-10-03', 'Tagihan katering', [
        ['1210', '5000000', '0'], ['4101', '0', '5000000'],
    ]);
    // Pelunasannya masuk bank.
    jurnalArus($this, '2026-10-20', 'Pelunasan tagihan katering', [
        ['1110', '5000000', '0'], ['1210', '0', '5000000'],
    ]);

    $tabel = Factory::tenant($this->company, fn () => app(CashFlowStatement::class)
        ->build(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')));
    $baris = collect($tabel->rows)->keyBy('name');

    // Piutang usaha adalah aset lancar, bukan aset tetap: penagihannya arus kas OPERASI.
    expect($baris->get('Arus kas bersih dari aktivitas operasi')['amount'])->toBe('5000000.00')
        ->and($baris->get('Arus kas bersih dari aktivitas investasi')['amount'])->toBe('0.00');

    // Penjualan 5 juta diakui saat tagihan terbit, tetapi kas baru datang belakangan — itulah
    // sebabnya laba dan arus kas memang boleh berbeda.
    $labaRugi = Factory::tenant($this->company, fn () => app(FinancialStatements::class)
        ->incomeStatement(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-19')));
    $laba = collect($labaRugi->rows)->firstWhere('name', 'LABA (RUGI) BERSIH');
    expect($laba['amount'])->toBe('5000000.00');

    $arusSampai19 = Factory::tenant($this->company, fn () => app(CashFlowStatement::class)
        ->build(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-19')));
    expect(collect($arusSampai19->rows)->keyBy('name')->get('KENAIKAN (PENURUNAN) KAS BERSIH')['amount'])
        ->toBe('0.00');
});

it('menyusun perubahan ekuitas yang menutup sendiri', function () {
    jurnalArus($this, '2026-09-20', 'Setoran modal awal', [
        ['1110', '30000000', '0'], ['3101', '0', '30000000'],
    ]);
    jurnalArus($this, '2026-10-05', 'Penjualan tunai', [
        ['1101', '8000000', '0'], ['4101', '0', '8000000'],
    ]);
    jurnalArus($this, '2026-10-06', 'Bayar gaji', [
        ['6101', '3000000', '0'], ['1110', '0', '3000000'],
    ]);
    jurnalArus($this, '2026-10-25', 'Pengambilan pemilik', [
        ['3202', '2000000', '0'], ['1110', '0', '2000000'],
    ]);

    $tabel = Factory::tenant($this->company, fn () => app(FinancialStatements::class)
        ->equityChanges(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')));
    $baris = collect($tabel->rows)->keyBy('name');

    expect($baris->get('Ekuitas awal periode')['amount'])->toBe('30000000.00')
        ->and($baris->get('Laba (rugi) periode berjalan')['amount'])->toBe('5000000.00')
        // Prive tampil negatif karena ia mengurangi ekuitas.
        ->and($baris->get('3202 — Prive / Pengambilan Pemilik')['amount'])->toBe('-2000000.00')
        ->and($baris->get('EKUITAS AKHIR PERIODE')['amount'])->toBe('33000000.00')
        ->and(implode(' ', $tabel->notes))->not->toContain('PERINGATAN');

    // Dan angkanya sama persis dengan jumlah ekuitas di Neraca pada tanggal yang sama.
    $neraca = Factory::tenant($this->company, fn () => app(FinancialStatements::class)
        ->balanceSheet(CarbonImmutable::parse('2026-09-30'), CarbonImmutable::parse('2026-10-31')));
    $ekuitasNeraca = collect($neraca->rows)->firstWhere('name', 'Jumlah Ekuitas');
    expect($ekuitasNeraca['amount'])->toBe('33000000.00');
});

it('mengatakan apa adanya bila bagan akun belum punya kelompok kas', function () {
    // Entitas baru tanpa template bagan akun: laporannya kosong, dan alasannya disebutkan.
    app(TenantContext::class)->reset();
    [$polos] = Factory::company('Warung Belum Siap');

    $tabel = Factory::tenant($polos, fn () => app(CashFlowStatement::class)
        ->build(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')));

    expect($tabel->rows)->toBe([])
        ->and($tabel->notes[0])->toContain('Kas & Setara Kas');
});
