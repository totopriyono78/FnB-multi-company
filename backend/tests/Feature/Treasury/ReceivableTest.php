<?php

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Treasury\Application\CashAccountService;
use App\Modules\Treasury\Application\ReceivableService;
use App\Modules\Treasury\Application\TreasuryException;
use App\Modules\Treasury\Domain\Models\Customer;
use App\Modules\Treasury\Domain\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Tests\Support\Factory;

/**
 * Tagihan keluar & piutang usaha (AR-01, AR-02).
 *
 * Janji yang diuji: **pendapatan diakui saat tagihan terbit, bukan saat dibayar.** Itulah yang
 * membuat laba bulan ini tidak bergantung pada kapan pelanggan membayar — dan itu pula yang paling
 * mudah salah bila pelunasan diam-diam menyentuh akun pendapatan lagi.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kedai Piutang');
    $this->outlet = Factory::outlet($this->company, null, ['code' => 'PTG']);
    [$this->finance] = Factory::staff($this->company, ['finance'], []);
    [$this->finance2] = Factory::staff($this->company, ['finance'], []);

    Factory::tenant($this->company, function (): void {
        app(ChartOfAccounts::class)->installTemplate();
        $this->pelanggan = Customer::query()->create([
            'code' => 'PLG1', 'name' => 'PT Kantor Sebelah', 'payment_term_days' => 30,
        ]);
        $this->bank = app(CashAccountService::class)->create([
            'code' => 'BCA', 'name' => 'BCA Operasional',
            'account_id' => Account::query()->where('code', '1110')->value('id'),
        ]);
    });
});

afterEach(fn () => app(TenantContext::class)->reset());

function dalamPiutang(object $test, Closure $fn): mixed
{
    return Factory::tenant($test->company, $fn);
}

function tagihanBaru(object $test, array $ubah = []): SalesInvoice
{
    return dalamPiutang($test, fn () => app(ReceivableService::class)->create(array_merge([
        'customer_id' => $test->pelanggan->id,
        'invoice_date' => '2026-10-05',
        'description' => 'Katering rapat bulanan',
        'outlet_id' => $test->outlet->id,
        'lines' => [[
            'description' => 'Paket nasi kotak 100 porsi',
            'account_id' => Account::query()->where('code', '4101')->value('id'),
            'quantity' => '100', 'unit_price' => '35000', 'amount' => '3500000',
        ]],
    ], $ubah), $test->finance));
}

describe('tagihan keluar (AR-02)', function () {
    it('mengakui pendapatan dan piutang saat diterbitkan', function () {
        $tagihan = tagihanBaru($this);

        expect($tagihan->number)->toBe('FJ-2610-0001')
            // Jatuh tempo mengikuti termin pelanggan (30 hari).
            ->and($tagihan->due_date->format('Y-m-d'))->toBe('2026-11-04')
            ->and($tagihan->status)->toBe(SalesInvoice::DRAFT);

        $terbit = dalamPiutang($this, fn () => app(ReceivableService::class)->issue($tagihan, $this->finance));
        $baris = dalamPiutang($this, fn () => Journal::query()->whereKey($terbit->journal_id)->with('lines.account')->sole()
            ->lines->mapWithKeys(fn ($l) => [$l->account->code => ['d' => (string) $l->debit, 'k' => (string) $l->credit]]));

        expect($terbit->status)->toBe(SalesInvoice::ISSUED)
            ->and($baris['1210']['d'])->toBe('3500000.00')
            ->and($baris['4101']['k'])->toBe('3500000.00')
            // Tidak ada kas yang bergerak saat tagihan terbit.
            ->and($baris)->not->toHaveKey('1110');
    });

    it('memungut PPN keluaran bila berfaktur pajak', function () {
        $tagihan = tagihanBaru($this, [
            'has_tax_invoice' => true, 'tax_amount' => '385000',
            'tax_invoice_no' => '010.000-26.00000009', 'tax_invoice_date' => '2026-10-05',
        ]);

        expect($tagihan->total)->toBe('3885000.00');

        $terbit = dalamPiutang($this, fn () => app(ReceivableService::class)->issue($tagihan, $this->finance));
        $baris = dalamPiutang($this, fn () => Journal::query()->whereKey($terbit->journal_id)->with('lines.account')->sole()
            ->lines->mapWithKeys(fn ($l) => [$l->account->code => ['d' => (string) $l->debit, 'k' => (string) $l->credit]]));

        // Piutang sebesar tagihan penuh; PPN keluaran menjadi hutang pajak, bukan pendapatan.
        expect($baris['1210']['d'])->toBe('3885000.00')
            ->and($baris['4101']['k'])->toBe('3500000.00')
            ->and($baris['2202']['k'])->toBe('385000.00');
    });

    it('menolak faktur pajak tanpa nomornya', function () {
        expect(fn () => tagihanBaru($this, ['has_tax_invoice' => true, 'tax_amount' => '385000']))
            ->toThrow(fn (TreasuryException $e) => expect($e->errorCode)->toBe('TAX_INVOICE_NO_REQUIRED'));
    });
});

describe('pelunasan (AR-02)', function () {
    it('menerima pelunasan ke rekening, dan pendapatan tidak disentuh lagi', function () {
        $tagihan = dalamPiutang($this, fn () => app(ReceivableService::class)
            ->issue(tagihanBaru($this), $this->finance));

        $terima = dalamPiutang($this, fn () => app(ReceivableService::class)->receive($tagihan, [
            'cash_account_id' => $this->bank->id, 'received_on' => '2026-11-03',
            'amount' => '3500000', 'reference' => 'TRF-IN-01',
        ], $this->finance));

        expect($terima->number)->toBe('PP-2611-0001');

        $baris = dalamPiutang($this, fn () => Journal::query()->whereKey($terima->journal_id)->with('lines.account')->sole()
            ->lines->mapWithKeys(fn ($l) => [$l->account->code => ['d' => (string) $l->debit, 'k' => (string) $l->credit]]));

        // Inti akrual dari sisi piutang: pelunasan memindahkan piutang menjadi kas, titik.
        expect($baris['1110']['d'])->toBe('3500000.00')
            ->and($baris['1210']['k'])->toBe('3500000.00')
            ->and($baris)->not->toHaveKey('4101');

        $sesudah = dalamPiutang($this, fn () => SalesInvoice::query()->findOrFail($tagihan->id));
        expect($sesudah->status)->toBe(SalesInvoice::PAID)
            ->and($sesudah->outstanding()->isZero())->toBeTrue();
    });

    it('menerima pelunasan sebagian dan menolak yang melebihi sisa', function () {
        $tagihan = dalamPiutang($this, fn () => app(ReceivableService::class)
            ->issue(tagihanBaru($this), $this->finance));

        dalamPiutang($this, function () use ($tagihan): void {
            $service = app(ReceivableService::class);
            $service->receive($tagihan, ['cash_account_id' => $this->bank->id,
                'received_on' => '2026-11-03', 'amount' => '1500000'], $this->finance);

            $tengah = SalesInvoice::query()->findOrFail($tagihan->id);
            expect($tengah->status)->toBe(SalesInvoice::ISSUED)
                ->and((string) $tengah->outstanding()->toScale(2))->toBe('2000000.00');

            expect(fn () => $service->receive($tengah, ['cash_account_id' => $this->bank->id,
                'received_on' => '2026-11-05', 'amount' => '2000001'], $this->finance))
                ->toThrow(fn (TreasuryException $e) => expect($e->errorCode)->toBe('RECEIPT_EXCEEDS_INVOICE'));
        });
    });

    it('menolak pelunasan tagihan yang masih draft', function () {
        $draft = tagihanBaru($this);

        expect(fn () => dalamPiutang($this, fn () => app(ReceivableService::class)->receive($draft, [
            'cash_account_id' => $this->bank->id, 'amount' => '100000',
        ], $this->finance)))->toThrow(fn (TreasuryException $e) => expect($e->errorCode)->toBe('INVOICE_NOT_ISSUED'));
    });
});

it('mengelompokkan umur piutang dan mengabaikan yang sudah lunas', function () {
    dalamPiutang($this, function (): void {
        $service = app(ReceivableService::class);
        $akun = Account::query()->where('code', '4101')->value('id');
        $buat = fn (string $tanggal, string $tempo, string $nilai) => $service->issue($service->create([
            'customer_id' => $this->pelanggan->id, 'invoice_date' => $tanggal, 'due_date' => $tempo,
            'description' => 'Tagihan '.$tanggal,
            'lines' => [['description' => 'Jasa katering', 'account_id' => $akun,
                'quantity' => '1', 'unit_price' => $nilai, 'amount' => $nilai]],
        ], $this->finance), $this->finance);

        $buat('2026-09-01', '2026-11-30', '1000000');   // belum jatuh tempo
        $buat('2026-09-01', '2026-10-20', '2000000');   // 11 hari
        $lunas = $buat('2026-08-01', '2026-09-01', '5000000');
        $service->receive($lunas, ['cash_account_id' => $this->bank->id,
            'received_on' => '2026-09-20', 'amount' => '5000000'], $this->finance);

        $tabel = $service->aging(CarbonImmutable::parse('2026-10-31'));
        $baris = collect($tabel->rows)->firstWhere('customer', 'PT Kantor Sebelah');

        expect($baris['not_due'])->toBe('1000000.00')
            ->and($baris['d1_30'])->toBe('2000000.00')
            // Yang sudah lunas tidak muncul lagi — piutang yang sudah diterima bukan piutang.
            ->and($baris['total'])->toBe('3000000.00')
            ->and($tabel->totals['total'])->toBe('3000000.00');
    });
});

it('tidak membocorkan pelanggan dan tagihan antar entitas', function () {
    tagihanBaru($this);

    app(TenantContext::class)->reset();
    [$lain] = Factory::company('Warung Tetangga');
    Factory::tenant($lain, function (): void {
        expect(SalesInvoice::query()->count())->toBe(0)
            ->and(Customer::query()->count())->toBe(0);
    });
});
