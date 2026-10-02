<?php

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Treasury\Application\BankReconciliationService;
use App\Modules\Treasury\Application\CashAccountService;
use App\Modules\Treasury\Application\CashTransactionService;
use App\Modules\Treasury\Application\TreasuryException;
use App\Modules\Treasury\Domain\Models\BankStatementLine;
use App\Modules\Treasury\Domain\Models\CashTransaction;
use Tests\Support\Factory;

/**
 * Rekonsiliasi bank (CSH-04).
 *
 * Yang diuji bukan "pencocokan berhasil", melainkan **kapan sistem menolak menebak**. Rekonsiliasi
 * yang mencocokkan asal-asalan jauh lebih berbahaya daripada rekonsiliasi yang menyerah: yang
 * pertama terlihat selesai sambil menyembunyikan dua kesalahan sekaligus.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kedai Rekonsiliasi');
    [$this->finance] = Factory::staff($this->company, ['finance'], []);
    [$this->finance2] = Factory::staff($this->company, ['finance'], []);

    Factory::tenant($this->company, function (): void {
        app(ChartOfAccounts::class)->installTemplate();
        $this->bank = app(CashAccountService::class)->create([
            'code' => 'BCA', 'name' => 'BCA Operasional', 'bank_name' => 'BCA', 'account_number' => '1234567890',
            'account_id' => Account::query()->where('code', '1110')->value('id'),
        ]);
    });
    $this->dir = sys_get_temp_dir().'/rekon-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function () {
    app(TenantContext::class)->reset();
    foreach (glob($this->dir.'/*') ?: [] as $f) {
        unlink($f);
    }
    @rmdir($this->dir);
});

function dalamRekon(object $test, Closure $fn): mixed
{
    return Factory::tenant($test->company, $fn);
}

/** Catat mutasi kas lalu posting jurnalnya, supaya ia benar-benar ada di buku besar. */
function mutasiTerposting(object $test, string $kind, string $tanggal, string $nilai, string $keterangan): CashTransaction
{
    return dalamRekon($test, function () use ($test, $kind, $tanggal, $nilai, $keterangan): CashTransaction {
        $tx = app(CashTransactionService::class)->record([
            'kind' => $kind, 'transaction_date' => $tanggal,
            'cash_account_id' => $test->bank->id,
            'contra_account_id' => Account::query()->where('code', $kind === CashTransaction::IN ? '3101' : '6108')->value('id'),
            'amount' => $nilai, 'description' => $keterangan,
        ], $test->finance);

        $journals = app(JournalService::class);
        $jurnal = Journal::query()->findOrFail($tx->journal_id);
        $journals->post($journals->submit($jurnal, $test->finance)->refresh(), $test->finance2);

        return $tx;
    });
}

function tulisCsv(object $test, string $isi): string
{
    $path = $test->dir.'/koran.csv';
    file_put_contents($path, $isi);

    return $path;
}

it('mengimpor rekening koran dan mengumpulkan seluruh masalah barisnya sekaligus', function () {
    $path = tulisCsv($this, <<<'CSV'
    Tanggal,Keterangan,Referensi,Debet,Kredit
    05/10/2026,Setoran modal,TRF001,0,5.000.000
    06/10/2026,Biaya administrasi,,15.000,0
    bukan tanggal,Baris rusak,,1000,0
    07/10/2026,Dua-duanya terisi,,500,500
    08/10/2026,Kosong dua-duanya,,0,0
    CSV);

    $hasil = dalamRekon($this, fn () => app(BankReconciliationService::class)
        ->import($this->bank, $path, 'koran.csv', $this->finance));

    expect($hasil['imported'])->toBe(2)
        // Tiga baris bermasalah dilaporkan sekaligus, bukan berhenti di yang pertama: berkas dari
        // bank jarang rapi, dan mengulang impor belasan kali bukan cara bekerja.
        ->and($hasil['problems'])->toHaveCount(3)
        ->and($hasil['statement']->period_start->format('Y-m-d'))->toBe('2026-10-05')
        ->and($hasil['statement']->period_end->format('Y-m-d'))->toBe('2026-10-06');

    // Pemisah ribuan gaya Indonesia terbaca benar. Dibaca di dalam konteks tenant: tanpa itu RLS
    // menyembunyikan barisnya dan kegagalannya terbaca seperti impor yang tidak menyimpan apa pun.
    $baris = dalamRekon($this, fn () => $hasil['statement']->lines()->get());
    expect((string) $baris[0]->credit)->toBe('5000000.00')
        ->and((string) $baris[1]->debit)->toBe('15000.00');
});

it('mencocokkan otomatis hanya bila pasangannya tunggal', function () {
    mutasiTerposting($this, CashTransaction::IN, '2026-10-05', '5000000', 'Setoran modal awal');
    // Dua pengeluaran bernilai sama di tanggal berdekatan: kandidatnya jadi dua, dan sistem harus menyerah.
    mutasiTerposting($this, CashTransaction::OUT, '2026-10-06', '250000', 'Beli perlengkapan A');
    mutasiTerposting($this, CashTransaction::OUT, '2026-10-07', '250000', 'Beli perlengkapan B');

    $path = tulisCsv($this, <<<'CSV'
    Tanggal,Keterangan,Referensi,Debet,Kredit
    05/10/2026,Setoran modal,TRF001,0,5000000
    06/10/2026,Pembelian,TRF002,250000,0
    CSV);

    $hasil = dalamRekon($this, fn () => app(BankReconciliationService::class)
        ->import($this->bank, $path, 'koran.csv', $this->finance));
    $statement = $hasil['statement'];

    $cocok = dalamRekon($this, fn () => app(BankReconciliationService::class)->autoMatch($statement, $this->finance));

    // Setoran 5 juta tunggal → cocok. Pembelian 250rb punya dua kandidat → dibiarkan untuk orang.
    expect($cocok)->toBe(1);

    $baris = dalamRekon($this, fn () => $statement->lines()->get());
    expect($baris[0]->isMatched())->toBeTrue()
        ->and($baris[0]->match_mode)->toBe(BankStatementLine::AUTO)
        ->and($baris[1]->isMatched())->toBeFalse()
        ->and(dalamRekon($this, fn () => app(BankReconciliationService::class)
            ->candidatesFor($statement, $baris[1])->count()))->toBe(2);
});

it('menolak mengunci selama masih ada baris menggantung, dan membekukan hasilnya setelah dikunci', function () {
    mutasiTerposting($this, CashTransaction::IN, '2026-10-05', '5000000', 'Setoran modal awal');

    $path = tulisCsv($this, <<<'CSV'
    Tanggal,Keterangan,Referensi,Debet,Kredit
    05/10/2026,Setoran modal,TRF001,0,5000000
    06/10/2026,Biaya administrasi,,15000,0
    CSV);

    $statement = dalamRekon($this, fn () => app(BankReconciliationService::class)
        ->import($this->bank, $path, 'koran.csv', $this->finance))['statement'];

    dalamRekon($this, function () use ($statement): void {
        $service = app(BankReconciliationService::class);
        $service->autoMatch($statement, $this->finance);

        // Biaya administrasi tidak pernah ada di buku kita — justru itu temuan rekonsiliasi.
        expect(fn () => $service->lock($statement, $this->finance))
            ->toThrow(fn (TreasuryException $e) => expect($e->errorCode)->toBe('STATEMENT_NOT_RECONCILED'));

        $biaya = $statement->lines()->where('line_no', 2)->sole();

        // Mengabaikan tanpa alasan ditolak: itu cara paling halus menutup selisih tanpa menjelaskannya.
        expect(fn () => $service->ignore($statement, $biaya, '', $this->finance))
            ->toThrow(fn (TreasuryException $e) => expect($e->errorCode)->toBe('IGNORE_REASON_REQUIRED'));

        $service->ignore($statement, $biaya, 'Biaya admin bank, akan dijurnal terpisah bulan ini.', $this->finance);
        $terkunci = $service->lock($statement->refresh(), $this->finance);
        expect($terkunci->isLocked())->toBeTrue();

        // Setelah dikunci, tidak ada yang bisa diubah lagi.
        expect(fn () => $service->unmatch($terkunci, $statement->lines()->where('line_no', 1)->sole(), $this->finance))
            ->toThrow(fn (TreasuryException $e) => expect($e->errorCode)->toBe('STATEMENT_LOCKED'));
    });
});

it('menjelaskan seluruh selisih di ringkasan rekonsiliasi', function () {
    mutasiTerposting($this, CashTransaction::IN, '2026-10-05', '5000000', 'Setoran modal awal');
    // Dicatat di buku pada 8 Okt, tetapi belum muncul di rekening koran yang berakhir 6 Okt.
    mutasiTerposting($this, CashTransaction::OUT, '2026-10-08', '300000', 'Transfer yang belum kliring');

    $path = tulisCsv($this, <<<'CSV'
    Tanggal,Keterangan,Referensi,Debet,Kredit
    05/10/2026,Setoran modal,TRF001,0,5000000
    06/10/2026,Biaya administrasi,,15000,0
    CSV);

    $statement = dalamRekon($this, fn () => app(BankReconciliationService::class)
        ->import($this->bank, $path, 'koran.csv', $this->finance))['statement'];

    dalamRekon($this, function () use ($statement): void {
        $service = app(BankReconciliationService::class);
        $service->autoMatch($statement, $this->finance);

        /*
         * Saldo rekening koran menurut bank: 5.000.000 − 15.000 = 4.985.000.
         * Saldo buku per 6 Okt: 5.000.000 (transfer 8 Okt belum terhitung karena di luar tanggal).
         */
        $statement->forceFill(['closing_balance' => '4985000.00'])->save();

        $tabel = $service->summary($statement->refresh());
        $baris = collect($tabel->rows)->keyBy('name');

        expect($baris->get('Saldo buku per 06 Okt 2026')['amount'])->toBe('5000000.00')
            ->and($baris->get('Saldo rekening koran')['amount'])->toBe('4985000.00')
            // Biaya admin 15rb yang belum masuk buku persis menjelaskan selisihnya.
            ->and($baris->get('SELISIH YANG BELUM DIJELASKAN')['amount'])->toBe('0.00')
            ->and($tabel->notes[0])->toContain('sudah dijelaskan');
    });
});

it('menolak mencocokkan baris jurnal milik akun lain', function () {
    $masuk = mutasiTerposting($this, CashTransaction::IN, '2026-10-05', '5000000', 'Setoran modal awal');

    $path = tulisCsv($this, <<<'CSV'
    Tanggal,Keterangan,Referensi,Debet,Kredit
    05/10/2026,Setoran modal,TRF001,0,5000000
    CSV);

    $statement = dalamRekon($this, fn () => app(BankReconciliationService::class)
        ->import($this->bank, $path, 'koran.csv', $this->finance))['statement'];

    dalamRekon($this, function () use ($statement, $masuk): void {
        // Baris lawan jurnalnya (akun modal), bukan baris banknya.
        $modal = Account::query()->where('code', '3101')->value('id');
        $barisModal = Journal::query()->findOrFail($masuk->journal_id)
            ->lines()->where('account_id', $modal)->sole();

        expect(fn () => app(BankReconciliationService::class)->match(
            $statement, $statement->lines()->sole(), $barisModal->id, $this->finance))
            ->toThrow(fn (TreasuryException $e) => expect($e->errorCode)->toBe('JOURNAL_LINE_MISMATCH'));
    });
});
