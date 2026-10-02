<?php

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Documents\Application\ApprovalMatrix;
use App\Modules\Documents\Application\PaymentAdviceService;
use App\Modules\Documents\Application\PaymentRequestService;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Treasury\Application\PayableService;
use App\Modules\Treasury\Application\PurchaseInvoiceService;
use App\Modules\Treasury\Application\TreasuryException;
use App\Modules\Treasury\Domain\Models\PurchaseInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Support\Factory;

/**
 * Faktur pembelian & hutang usaha (AP-02, AP-03, AP-04, TAX-02).
 *
 * Dua janji yang diuji berkas ini:
 *
 * 1. **Beban diakui saat terjadi, bukan saat dibayar.** Faktur yang diterbitkan langsung memunculkan
 *    hutang dan bebannya; pembayarannya kemudian tidak menyentuh beban sama sekali.
 * 2. **Satu tagihan tidak bisa dibayar dua kali.** Dijaga di dua lapis: nomor faktur supplier yang
 *    unik, dan alokasi pembayaran yang tidak pernah melebihi sisa hutang.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kedai Hutang');
    $this->outlet = Factory::outlet($this->company, null, ['code' => 'HTG']);
    [$this->finance] = Factory::staff($this->company, ['finance'], []);
    [$this->finance2] = Factory::staff($this->company, ['finance'], []);
    [$this->admin] = Factory::staff($this->company, ['company_admin'], []);

    Factory::tenant($this->company, function (): void {
        app(ChartOfAccounts::class)->installTemplate();
        app(ApprovalMatrix::class)->installDefaults();
        $this->supplier = Supplier::query()->create([
            'code' => 'SUP1', 'name' => 'CV Bahan Segar', 'payment_term_days' => 14, 'is_pkp' => false,
        ]);
        $this->supplierPkp = Supplier::query()->create([
            'code' => 'SUP2', 'name' => 'PT Pemasok Resmi', 'payment_term_days' => 30, 'is_pkp' => true,
            'npwp' => '012345678901000',
        ]);
    });
});

afterEach(fn () => app(TenantContext::class)->reset());

function dalamHutang(object $test, Closure $fn): mixed
{
    return Factory::tenant($test->company, $fn);
}

function akunHutangKode(object $test, string $code): string
{
    return (string) dalamHutang($test, fn () => Account::query()->where('code', $code)->value('id'));
}

function fakturBaru(object $test, array $ubah = []): PurchaseInvoice
{
    return dalamHutang($test, fn () => app(PurchaseInvoiceService::class)->create(array_merge([
        'supplier_id' => $test->supplier->id,
        'invoice_date' => '2026-10-05',
        'supplier_invoice_no' => 'INV-'.substr(md5((string) mt_rand()), 0, 8),
        'description' => 'Belanja bahan baku mingguan',
        'outlet_id' => $test->outlet->id,
        'lines' => [[
            'description' => 'Kopi arabika 10 kg',
            'account_id' => akunHutangKode($test, '1301'),
            'quantity' => '10', 'unit_price' => '120000', 'amount' => '1200000',
        ]],
    ], $ubah), $test->finance));
}

describe('faktur pembelian (AP-02)', function () {
    it('mengakui beban dan hutang saat diterbitkan, bukan saat dibayar', function () {
        $faktur = fakturBaru($this);

        expect($faktur->status)->toBe(PurchaseInvoice::DRAFT)
            ->and($faktur->number)->toBe('FB-2610-0001')
            // Jatuh tempo mengikuti termin supplier (14 hari) tanpa perlu diisi.
            ->and($faktur->due_date->format('Y-m-d'))->toBe('2026-10-19')
            ->and($faktur->total)->toBe('1200000.00');

        $terbit = dalamHutang($this, fn () => app(PurchaseInvoiceService::class)->issue($faktur, $this->finance));
        expect($terbit->status)->toBe(PurchaseInvoice::ISSUED);

        $baris = dalamHutang($this, fn () => Journal::query()->whereKey($terbit->journal_id)->with('lines.account')->sole()
            ->lines->mapWithKeys(fn ($l) => [$l->account->code => ['d' => (string) $l->debit, 'k' => (string) $l->credit]]));

        // Persediaan bertambah, hutang usaha bertambah — tidak ada kas yang bergerak.
        expect($baris['1301']['d'])->toBe('1200000.00')
            ->and($baris['2101']['k'])->toBe('1200000.00')
            ->and($baris)->not->toHaveKey('1110');
    });

    it('memisahkan PPN masukan hanya bila ada faktur pajak', function () {
        // Supplier PKP: bawaannya ada faktur pajak, jadi nomornya wajib.
        expect(fn () => dalamHutang($this, fn () => app(PurchaseInvoiceService::class)->create([
            'supplier_id' => $this->supplierPkp->id, 'invoice_date' => '2026-10-05',
            'description' => 'Pembelian dengan faktur pajak', 'tax_amount' => '110000',
            'lines' => [['description' => 'Gula 50 kg', 'account_id' => akunHutangKode($this, '1301'),
                'quantity' => '50', 'unit_price' => '20000', 'amount' => '1000000']],
        ], $this->finance)))->toThrow(fn (TreasuryException $e) => expect($e->errorCode)->toBe('TAX_INVOICE_NO_REQUIRED'));

        $faktur = dalamHutang($this, fn () => app(PurchaseInvoiceService::class)->create([
            'supplier_id' => $this->supplierPkp->id, 'invoice_date' => '2026-10-05',
            'description' => 'Pembelian dengan faktur pajak',
            'tax_amount' => '110000', 'tax_invoice_no' => '010.000-26.00000001', 'tax_invoice_date' => '2026-10-05',
            'lines' => [['description' => 'Gula 50 kg', 'account_id' => akunHutangKode($this, '1301'),
                'quantity' => '50', 'unit_price' => '20000', 'amount' => '1000000']],
        ], $this->finance));

        expect($faktur->has_tax_invoice)->toBeTrue()
            ->and($faktur->subtotal)->toBe('1000000.00')
            ->and($faktur->total)->toBe('1110000.00');

        $terbit = dalamHutang($this, fn () => app(PurchaseInvoiceService::class)->issue($faktur, $this->finance));
        $baris = dalamHutang($this, fn () => Journal::query()->whereKey($terbit->journal_id)->with('lines.account')->sole()
            ->lines->mapWithKeys(fn ($l) => [$l->account->code => ['d' => (string) $l->debit, 'k' => (string) $l->credit]]));

        // PPN masukan adalah ASET yang dapat dikreditkan, bukan beban — ia tidak menambah harga perolehan.
        expect($baris['1301']['d'])->toBe('1000000.00')
            ->and($baris['1220']['d'])->toBe('110000.00')
            ->and($baris['2101']['k'])->toBe('1110000.00');
    });

    it('tidak memisahkan PPN untuk supplier yang belum PKP', function () {
        $faktur = fakturBaru($this);

        expect($faktur->has_tax_invoice)->toBeFalse()
            ->and($faktur->tax_amount)->toBe('0.00')
            // Seluruh nilainya masuk harga perolehan, apa adanya.
            ->and($faktur->total)->toBe($faktur->subtotal);
    });

    it('menolak nomor faktur supplier yang sama dua kali', function () {
        fakturBaru($this, ['supplier_invoice_no' => 'INV-KEMBAR']);

        /*
         * Inilah penjaga yang mencegah kerugian paling senyap di hutang usaha: faktur yang sama masuk
         * dua kali, dibayar dua kali, dan buku besarnya tetap seimbang sepanjang waktu.
         */
        expect(fn () => fakturBaru($this, ['supplier_invoice_no' => 'INV-KEMBAR']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('menolak mengubah faktur yang sudah diterbitkan', function () {
        $faktur = fakturBaru($this);
        $terbit = dalamHutang($this, fn () => app(PurchaseInvoiceService::class)->issue($faktur, $this->finance));

        expect(fn () => dalamHutang($this, fn () => app(PurchaseInvoiceService::class)
            ->update($terbit, ['description' => 'Diubah diam-diam'], $this->finance)))
            ->toThrow(fn (TreasuryException $e) => expect($e->errorCode)->toBe('INVOICE_NOT_EDITABLE'));
    });
});

describe('pelunasan lewat SPPK (AP-03)', function () {
    it('melunasi faktur lewat advis bayar, dan jurnalnya memindahkan hutang jadi uang keluar', function () {
        $faktur = dalamHutang($this, fn () => app(PurchaseInvoiceService::class)
            ->issue(fakturBaru($this), $this->finance));

        $hasil = dalamHutang($this, function () use ($faktur) {
            $requests = app(PaymentRequestService::class);
            // SPPK menunjuk Utang Usaha, bukan akun beban: bebannya sudah diakui saat faktur terbit.
            $sppk = $requests->create([
                'request_date' => '2026-10-19', 'supplier_id' => $this->supplier->id,
                'amount' => '1200000', 'expense_account_id' => Account::query()->where('code', '2101')->value('id'),
                'description' => 'Pelunasan faktur '.$faktur->number,
            ], $this->finance);

            app(PayableService::class)->attachInvoices($sppk, [$faktur->id => '1200000']);
            $disetujui = $requests->approve($requests->submit($sppk, $this->finance), $this->finance2);

            return app(PaymentAdviceService::class)->issue($disetujui, [
                'paid_on' => '2026-10-19', 'amount' => '1200000',
                'bank_account_id' => Account::query()->where('code', '1110')->value('id'),
            ], $this->finance);
        });

        $sesudah = dalamHutang($this, fn () => PurchaseInvoice::query()->findOrFail($faktur->id));
        expect($sesudah->status)->toBe(PurchaseInvoice::PAID)
            ->and($sesudah->paid_amount)->toBe('1200000.00')
            ->and($sesudah->outstanding()->isZero())->toBeTrue();

        $baris = dalamHutang($this, fn () => Journal::query()->whereKey($hasil->journal_id)->with('lines.account')->sole()
            ->lines->mapWithKeys(fn ($l) => [$l->account->code => ['d' => (string) $l->debit, 'k' => (string) $l->credit]]));

        // Hutang berkurang, bank berkurang — beban TIDAK disentuh lagi. Itulah inti akrual.
        expect($baris['2101']['d'])->toBe('1200000.00')
            ->and($baris['1110']['k'])->toBe('1200000.00')
            ->and($baris)->not->toHaveKey('1301');
    });

    it('membayar sebagian, lalu melunasi sisanya', function () {
        $faktur = dalamHutang($this, fn () => app(PurchaseInvoiceService::class)
            ->issue(fakturBaru($this), $this->finance));

        dalamHutang($this, function () use ($faktur): void {
            $requests = app(PaymentRequestService::class);
            $hutang = Account::query()->where('code', '2101')->value('id');
            $bank = Account::query()->where('code', '1110')->value('id');

            $sppk = $requests->create([
                'request_date' => '2026-10-19', 'supplier_id' => $this->supplier->id,
                'amount' => '1200000', 'expense_account_id' => $hutang,
                'description' => 'Pelunasan bertahap faktur '.$faktur->number,
            ], $this->finance);
            app(PayableService::class)->attachInvoices($sppk, [$faktur->id => '1200000']);
            $disetujui = $requests->approve($requests->submit($sppk, $this->finance), $this->finance2);

            app(PaymentAdviceService::class)->issue($disetujui, [
                'paid_on' => '2026-10-19', 'amount' => '500000', 'bank_account_id' => $bank,
            ], $this->finance);

            $tengah = PurchaseInvoice::query()->findOrFail($faktur->id);
            expect($tengah->status)->toBe(PurchaseInvoice::ISSUED)
                ->and((string) $tengah->outstanding()->toScale(2))->toBe('700000.00');

            app(PaymentAdviceService::class)->issue($disetujui->refresh(), [
                'paid_on' => '2026-10-25', 'amount' => '700000', 'bank_account_id' => $bank,
            ], $this->finance);

            $akhir = PurchaseInvoice::query()->findOrFail($faktur->id);
            expect($akhir->status)->toBe(PurchaseInvoice::PAID)
                ->and($akhir->payments()->count())->toBe(2);
        });
    });

    it('menolak menunjuk faktur melebihi sisa hutangnya', function () {
        $faktur = dalamHutang($this, fn () => app(PurchaseInvoiceService::class)
            ->issue(fakturBaru($this), $this->finance));

        dalamHutang($this, function () use ($faktur): void {
            $sppk = app(PaymentRequestService::class)->create([
                'request_date' => '2026-10-19', 'supplier_id' => $this->supplier->id,
                'amount' => '2000000', 'expense_account_id' => Account::query()->where('code', '2101')->value('id'),
                'description' => 'Pelunasan kelebihan',
            ], $this->finance);

            expect(fn () => app(PayableService::class)->attachInvoices($sppk, [$faktur->id => '2000000']))
                ->toThrow(fn (TreasuryException $e) => expect($e->errorCode)->toBe('INVOICE_OVERPAY'));
        });
    });

    it('menolak menunjuk faktur yang belum diterbitkan', function () {
        $draft = fakturBaru($this);

        dalamHutang($this, function () use ($draft): void {
            $sppk = app(PaymentRequestService::class)->create([
                'request_date' => '2026-10-19', 'supplier_id' => $this->supplier->id,
                'amount' => '1200000', 'expense_account_id' => Account::query()->where('code', '2101')->value('id'),
                'description' => 'Pelunasan faktur draft',
            ], $this->finance);

            // Hutangnya belum ada di buku, jadi belum ada yang bisa dilunasi.
            expect(fn () => app(PayableService::class)->attachInvoices($sppk, [$draft->id => '1200000']))
                ->toThrow(fn (TreasuryException $e) => expect($e->errorCode)->toBe('INVOICE_NOT_PAYABLE'));
        });
    });
});

describe('umur hutang (AP-04)', function () {
    it('mengelompokkan sisa hutang menurut lama tertunggak', function () {
        dalamHutang($this, function (): void {
            $service = app(PurchaseInvoiceService::class);
            $akun = Account::query()->where('code', '1301')->value('id');
            $buat = fn (string $tanggal, string $tempo, string $nilai) => $service->issue($service->create([
                'supplier_id' => $this->supplier->id, 'invoice_date' => $tanggal, 'due_date' => $tempo,
                'description' => 'Tagihan '.$tanggal,
                'lines' => [['description' => 'Bahan', 'account_id' => $akun,
                    'quantity' => '1', 'unit_price' => $nilai, 'amount' => $nilai]],
            ], $this->finance), $this->finance);

            $buat('2026-09-01', '2026-11-30', '1000000');  // belum jatuh tempo
            $buat('2026-09-01', '2026-10-20', '2000000');  // 11 hari
            $buat('2026-08-01', '2026-09-15', '3000000');  // 46 hari
            $buat('2026-05-01', '2026-06-01', '4000000');  // 152 hari

            $tabel = app(PayableService::class)->aging(CarbonImmutable::parse('2026-10-31'));
            $baris = collect($tabel->rows)->firstWhere('supplier', 'CV Bahan Segar');

            expect($baris['not_due'])->toBe('1000000.00')
                ->and($baris['d1_30'])->toBe('2000000.00')
                ->and($baris['d31_60'])->toBe('3000000.00')
                ->and($baris['d90_plus'])->toBe('4000000.00')
                ->and($baris['total'])->toBe('10000000.00')
                ->and($tabel->totals['total'])->toBe('10000000.00');
        });
    });

    it('tidak menghitung faktur yang sudah lunas', function () {
        $faktur = dalamHutang($this, fn () => app(PurchaseInvoiceService::class)
            ->issue(fakturBaru($this), $this->finance));

        dalamHutang($this, function () use ($faktur): void {
            $requests = app(PaymentRequestService::class);
            $sppk = $requests->create([
                'request_date' => '2026-10-19', 'supplier_id' => $this->supplier->id,
                'amount' => '1200000', 'expense_account_id' => Account::query()->where('code', '2101')->value('id'),
                'description' => 'Pelunasan penuh',
            ], $this->finance);
            app(PayableService::class)->attachInvoices($sppk, [$faktur->id => '1200000']);
            $disetujui = $requests->approve($requests->submit($sppk, $this->finance), $this->finance2);
            app(PaymentAdviceService::class)->issue($disetujui, [
                'paid_on' => '2026-10-19', 'amount' => '1200000',
                'bank_account_id' => Account::query()->where('code', '1110')->value('id'),
            ], $this->finance);

            $tabel = app(PayableService::class)->aging(CarbonImmutable::parse('2026-10-31'));
            expect($tabel->totals['total'])->toBe('0.00')
                ->and($tabel->rows)->toBe([]);
        });
    });
});

it('tidak membocorkan faktur pembelian antar entitas', function () {
    fakturBaru($this);

    app(TenantContext::class)->reset();
    [$lain] = Factory::company('Warung Tetangga');
    Factory::tenant($lain, function (): void {
        expect(PurchaseInvoice::query()->count())->toBe(0);
    });
});
