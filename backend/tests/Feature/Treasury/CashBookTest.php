<?php

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Treasury\Application\CashAccountService;
use App\Modules\Treasury\Application\CashTransactionService;
use App\Modules\Treasury\Application\TreasuryException;
use App\Modules\Treasury\Domain\Models\CashAccount;
use App\Modules\Treasury\Domain\Models\CashTransaction;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Tests\Support\Factory;

/**
 * Kas & bank (CSH-01, CSH-02, CSH-03).
 *
 * Satu janji yang menaungi berkas ini: **saldo rekening selalu bisa dihitung ulang dari buku besar**.
 * Tidak ada angka saldo yang disimpan di mana pun, sehingga tidak ada angka yang bisa menyimpang
 * diam-diam dari jurnalnya.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kedai Kas');
    $this->outlet = Factory::outlet($this->company, null, ['code' => 'KAS']);
    [$this->finance] = Factory::staff($this->company, ['finance'], []);
    [$this->finance2] = Factory::staff($this->company, ['finance'], []);

    Factory::tenant($this->company, fn () => app(ChartOfAccounts::class)->installTemplate());
});

afterEach(fn () => app(TenantContext::class)->reset());

function dalamKas(object $test, Closure $fn): mixed
{
    return Factory::tenant($test->company, $fn);
}

function akunKode(object $test, string $code): Account
{
    return dalamKas($test, fn () => Account::query()->where('code', $code)->firstOrFail());
}

/** Posting jurnal lewat jalur normal: diajukan satu orang, diposting orang lain. */
function postingJurnal(object $test, string $journalId): Journal
{
    return dalamKas($test, function () use ($test, $journalId): Journal {
        $service = app(JournalService::class);
        $jurnal = Journal::query()->findOrFail($journalId);
        if ($jurnal->isDraft()) {
            $jurnal = $service->submit($jurnal, $test->finance)->refresh();
        }

        return $service->post($jurnal, $test->finance2);
    });
}

describe('master rekening (CSH-01)', function () {
    it('membuatkan akun buku besar sendiri bila tidak dipilih', function () {
        dalamKas($this, function (): void {
            $rekening = app(CashAccountService::class)->create([
                'code' => 'BCA', 'name' => 'BCA Operasional', 'kind' => CashAccount::BANK,
                'bank_name' => 'BCA', 'account_number' => '1234567890', 'account_holder' => 'PT Kedai Kas',
            ]);

            $akun = Account::query()->findOrFail($rekening->account_id);
            // Kode diambil dari celah kosong PERTAMA di bawah 1100: template memakai 1101, 1102,
            // 1103 dan 1110, jadi 1104 yang kosong lebih dulu — bukan 1111 di ujung.
            expect($akun->code)->toBe('1104')
                ->and($akun->is_postable)->toBeTrue()
                ->and($akun->type)->toBe(Account::ASSET)
                ->and($rekening->label())->toContain('1234567890');
        });
    });

    it('menolak dua rekening berbagi satu akun buku besar', function () {
        dalamKas($this, function (): void {
            $service = app(CashAccountService::class);
            $bank = Account::query()->where('code', '1110')->firstOrFail();

            $service->create(['code' => 'BNK1', 'name' => 'Bank Satu', 'account_id' => $bank->id]);

            /*
             * Inilah aturan yang membuat "saldo rekening ini" punya arti. Dua rekening di satu akun
             * hanya bisa dijumlahkan, tidak bisa dibedakan — dan rekonsiliasi bank jadi mustahil.
             */
            expect(fn () => $service->create(['code' => 'BNK2', 'name' => 'Bank Dua', 'account_id' => $bank->id]))
                ->toThrow(fn (TreasuryException $e) => expect($e->errorCode)->toBe('ACCOUNT_ALREADY_USED'));
        });
    });
});

describe('mutasi kas (CSH-02, CSH-03)', function () {
    it('mencatat setoran dari laci kasir ke bank sebagai transfer, dan saldonya ikut berpindah', function () {
        $hasil = dalamKas($this, function () {
            $akunService = app(CashAccountService::class);
            $laci = $akunService->create([
                'code' => 'LACI', 'name' => 'Kas Laci Kasir', 'kind' => CashAccount::CASH,
                'account_id' => Account::query()->where('code', '1101')->value('id'),
                'outlet_id' => $this->outlet->id,
            ]);
            $bank = $akunService->create([
                'code' => 'BCA', 'name' => 'BCA Operasional', 'kind' => CashAccount::BANK,
                'account_id' => Account::query()->where('code', '1110')->value('id'),
            ]);

            // Uang masuk ke laci dulu (setoran modal), supaya ada yang disetorkan.
            $awal = app(CashTransactionService::class)->record([
                'kind' => CashTransaction::IN, 'transaction_date' => '2026-10-05',
                'cash_account_id' => $laci->id,
                'contra_account_id' => Account::query()->where('code', '3101')->value('id'),
                'amount' => '5000000', 'description' => 'Setoran modal awal pemilik',
            ], $this->finance);

            $setor = app(CashTransactionService::class)->record([
                'kind' => CashTransaction::TRANSFER, 'transaction_date' => '2026-10-06',
                'cash_account_id' => $laci->id, 'counter_cash_account_id' => $bank->id,
                'amount' => '3000000', 'description' => 'Setoran hasil penjualan ke bank',
                'reference' => 'STR-001',
            ], $this->finance);

            return [$laci, $bank, $awal, $setor];
        });

        [$laci, $bank, $awal, $setor] = $hasil;
        expect($awal->number)->toBe('KM-2610-0001')
            ->and($setor->number)->toBe('TF-2610-0001');

        // Sebelum diposting, saldo buku masih nol: jurnal draft bukan uang.
        dalamKas($this, function () use ($laci): void {
            expect(app(CashAccountService::class)->balance($laci)->isZero())->toBeTrue();
        });

        postingJurnal($this, $awal->journal_id);
        postingJurnal($this, $setor->journal_id);

        dalamKas($this, function () use ($laci, $bank): void {
            $service = app(CashAccountService::class);
            expect((string) $service->balance($laci)->toScale(2))->toBe('2000000.00')
                ->and((string) $service->balance($bank)->toScale(2))->toBe('3000000.00');

            // Posisi kas menjumlahkan keduanya, dan jumlahnya tetap 5 juta — uang hanya berpindah.
            $tabel = $service->positions(CarbonImmutable::parse('2026-10-31'));
            expect($tabel->totals['balance'])->toBe('5000000.00');
        });
    });

    it('mencatat kas keluar dengan arah jurnal yang benar', function () {
        dalamKas($this, function (): void {
            $kasKecil = app(CashAccountService::class)->create([
                'code' => 'KECIL', 'name' => 'Kas Kecil Outlet', 'kind' => CashAccount::CASH,
                'account_id' => Account::query()->where('code', '1102')->value('id'),
                'outlet_id' => $this->outlet->id,
            ]);
            $beban = Account::query()->where('code', '6108')->firstOrFail();

            $keluar = app(CashTransactionService::class)->record([
                'kind' => CashTransaction::OUT, 'transaction_date' => '2026-10-07',
                'cash_account_id' => $kasKecil->id, 'contra_account_id' => $beban->id,
                'amount' => '150000', 'description' => 'Beli es batu dan gas',
            ], $this->finance);

            $baris = Journal::query()->whereKey($keluar->journal_id)->with('lines.account')->sole()
                ->lines->mapWithKeys(fn ($l) => [$l->account->code => ['d' => (string) $l->debit, 'k' => (string) $l->credit]]);

            expect($baris['6108']['d'])->toBe('150000.00')
                ->and($baris['1102']['k'])->toBe('150000.00')
                // Outlet ikut dari rekeningnya sendiri, tanpa perlu diisi ulang.
                ->and(Journal::query()->whereKey($keluar->journal_id)->sole()->lines->first()->outlet_id)
                ->toBe($this->outlet->id);
        });
    });

    it('menolak transfer ke rekening yang sama dan akun lawan yang itu-itu juga', function () {
        dalamKas($this, function (): void {
            $akunService = app(CashAccountService::class);
            $bank = $akunService->create([
                'code' => 'BCA', 'name' => 'BCA Operasional',
                'account_id' => Account::query()->where('code', '1110')->value('id'),
            ]);
            $service = app(CashTransactionService::class);

            expect(fn () => $service->record([
                'kind' => CashTransaction::TRANSFER, 'cash_account_id' => $bank->id,
                'counter_cash_account_id' => $bank->id, 'amount' => '1000', 'description' => 'Pindah ke diri sendiri',
            ], $this->finance))->toThrow(fn (TreasuryException $e) => expect($e->errorCode)->toBe('SELF_TRANSFER'));

            // Kas masuk yang akun lawannya akun rekening itu sendiri = jurnal yang tidak berarti apa-apa.
            expect(fn () => $service->record([
                'kind' => CashTransaction::IN, 'cash_account_id' => $bank->id,
                'contra_account_id' => $bank->account_id, 'amount' => '1000', 'description' => 'Masuk dari diri sendiri',
            ], $this->finance))->toThrow(fn (TreasuryException $e) => expect($e->errorCode)->toBe('SELF_CONTRA'));
        });
    });

    it('menghitung saldo per tanggal, bukan hanya saldo akhir', function () {
        $ids = dalamKas($this, function () {
            $bank = app(CashAccountService::class)->create([
                'code' => 'BCA', 'name' => 'BCA Operasional',
                'account_id' => Account::query()->where('code', '1110')->value('id'),
            ]);
            $modal = Account::query()->where('code', '3101')->value('id');
            $service = app(CashTransactionService::class);

            return [$bank, [
                $service->record(['kind' => CashTransaction::IN, 'transaction_date' => '2026-10-02',
                    'cash_account_id' => $bank->id, 'contra_account_id' => $modal,
                    'amount' => '1000000', 'description' => 'Setoran pertama'], $this->finance),
                $service->record(['kind' => CashTransaction::IN, 'transaction_date' => '2026-10-09',
                    'cash_account_id' => $bank->id, 'contra_account_id' => $modal,
                    'amount' => '2000000', 'description' => 'Setoran kedua'], $this->finance),
            ]];
        });

        [$bank, $mutasi] = $ids;
        foreach ($mutasi as $m) {
            postingJurnal($this, $m->journal_id);
        }

        dalamKas($this, function () use ($bank): void {
            $service = app(CashAccountService::class);
            expect((string) $service->balance($bank, CarbonImmutable::parse('2026-10-05'))->toScale(2))->toBe('1000000.00')
                ->and((string) $service->balance($bank, CarbonImmutable::parse('2026-10-31'))->toScale(2))->toBe('3000000.00');
        });
    });
});

it('tidak membocorkan rekening antar entitas', function () {
    dalamKas($this, fn () => app(CashAccountService::class)->create([
        'code' => 'BCA', 'name' => 'BCA Operasional',
        'account_id' => Account::query()->where('code', '1110')->value('id'),
    ]));

    app(TenantContext::class)->reset();
    [$lain] = Factory::company('Warung Tetangga');
    Factory::tenant($lain, function (): void {
        expect(CashAccount::query()->count())->toBe(0)
            ->and(CashTransaction::query()->count())->toBe(0);
    });
});

it('saldo hanya menghitung jurnal yang sudah diposting', function () {
    $bank = dalamKas($this, fn () => app(CashAccountService::class)->create([
        'code' => 'BCA', 'name' => 'BCA Operasional',
        'account_id' => Account::query()->where('code', '1110')->value('id'),
    ]));

    $masuk = dalamKas($this, fn () => app(CashTransactionService::class)->record([
        'kind' => CashTransaction::IN, 'transaction_date' => '2026-10-05',
        'cash_account_id' => $bank->id,
        'contra_account_id' => Account::query()->where('code', '3101')->value('id'),
        'amount' => '750000', 'description' => 'Penerimaan lain-lain',
    ], $this->finance));

    // Diajukan saja belum cukup — jurnal yang masih bisa dikembalikan ke draft bukan uang.
    dalamKas($this, fn () => app(JournalService::class)->submit(
        Journal::query()->findOrFail($masuk->journal_id), $this->finance));
    dalamKas($this, function () use ($bank): void {
        expect(app(CashAccountService::class)->balance($bank)->isZero())->toBeTrue();
    });

    postingJurnal($this, $masuk->journal_id);
    dalamKas($this, function () use ($bank): void {
        expect(app(CashAccountService::class)->balance($bank))->toEqual(BigDecimal::of('750000.00'));
    });
});
