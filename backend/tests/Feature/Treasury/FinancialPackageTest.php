<?php

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Reporting\Application\ReportAccess;
use App\Modules\Reporting\Application\ReportCatalog;
use App\Modules\Reporting\Application\ReportFilter;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Treasury\Application\CompletenessBoard;
use App\Modules\Treasury\Application\PurchaseInvoiceService;
use App\Modules\Treasury\Application\ReceivableService;
use App\Modules\Treasury\Application\VatRecapReport;
use App\Modules\Treasury\Domain\Models\Customer;
use Carbon\CarbonImmutable;
use Tests\Support\Factory;

/**
 * Paket laporan keuangan terjadwal (FIN-07, FIN-08) dan rekap PPN (TAX-02).
 *
 * Yang diuji di sini bukan isi tiap laporan — itu sudah diuji di tempatnya masing-masing — melainkan
 * bahwa laporan akuntansi **benar-benar terdaftar di katalog**, sehingga ia mendapat ekspor dan
 * penjadwalan email seperti laporan lain. Laporan yang tidak terdaftar hanya bisa dibuka manusia,
 * dan janji "paket LK tiap dua hari" akan bergantung pada seseorang yang ingat mengirimnya.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kedai Paket LK');
    $this->outlet = Factory::outlet($this->company, null, ['code' => 'PKT']);
    [$this->finance] = Factory::staff($this->company, ['finance'], []);
    [$this->finance2] = Factory::staff($this->company, ['finance'], []);
    [$this->kasir] = Factory::staff($this->company, ['cashier'], [$this->outlet->id]);

    Factory::tenant($this->company, fn () => app(ChartOfAccounts::class)->installTemplate());
});

afterEach(fn () => app(TenantContext::class)->reset());

function filterPaket(object $test, string $from = '2026-10-01', string $to = '2026-10-31'): ReportFilter
{
    return new ReportFilter(
        CarbonImmutable::parse($from),
        CarbonImmutable::parse($to),
        [$test->outlet->id],
    );
}

it('mendaftarkan seluruh laporan akuntansi di katalog, dan semuanya benar-benar bisa disusun', function () {
    Factory::tenant($this->company, function (): void {
        $katalog = ReportCatalog::all();
        $filter = filterPaket($this);

        foreach (array_keys(ReportCatalog::ACCOUNTING_REPORTS) as $key) {
            expect($katalog)->toHaveKey($key)
                ->and($katalog[$key]['kind'])->toBe(ReportAccess::ACCOUNTING);

            // Disusun sungguhan, bukan hanya dicek ada: laporan yang terdaftar tetapi meledak saat
            // dijadwalkan akan gagal diam-diam di tengah malam, di tempat yang tak seorang pun lihat.
            $tabel = app(ReportCatalog::class)->build($key, $filter);
            expect($tabel->key)->not->toBeEmpty()
                ->and($tabel->title)->not->toBeEmpty();
        }
    });
});

it('menjaga laporan akuntansi dari peran tanpa izin pembukuan', function () {
    $access = app(ReportAccess::class);

    Factory::tenant($this->company, function () use ($access): void {
        expect($access->can($this->finance->fresh(), ReportAccess::ACCOUNTING))->toBeTrue()
            // Kasir boleh melihat laporan penjualan shift-nya, tetapi tidak laporan keuangan entitas.
            ->and($access->can($this->kasir->fresh(), ReportAccess::ACCOUNTING))->toBeFalse();
    });
});

it('merekap PPN hanya dari dokumen yang punya nomor faktur pajak', function () {
    Factory::tenant($this->company, function (): void {
        $supplier = Supplier::query()->create([
            'code' => 'SUP-PKP', 'name' => 'PT Pemasok Resmi', 'is_pkp' => true, 'npwp' => '012345678901000',
        ]);
        $pelanggan = Customer::query()->create([
            'code' => 'PLG1', 'name' => 'PT Kantor Sebelah', 'npwp' => '098765432101000',
        ]);
        $beli = app(PurchaseInvoiceService::class);
        $jual = app(ReceivableService::class);

        // Berfaktur pajak: masuk rekap.
        $beli->issue($beli->create([
            'supplier_id' => $supplier->id, 'invoice_date' => '2026-10-05',
            'description' => 'Pembelian berfaktur pajak',
            'tax_amount' => '110000', 'tax_invoice_no' => '010.000-26.00000001', 'tax_invoice_date' => '2026-10-05',
            'lines' => [['description' => 'Bahan', 'account_id' => Account::query()->where('code', '1301')->value('id'),
                'quantity' => '1', 'unit_price' => '1000000', 'amount' => '1000000']],
        ], $this->finance), $this->finance);

        // Tanpa faktur pajak: TIDAK masuk rekap, walau nilainya besar.
        $beli->issue($beli->create([
            'supplier_id' => $supplier->id, 'invoice_date' => '2026-10-06', 'has_tax_invoice' => false,
            'description' => 'Pembelian tanpa faktur pajak',
            'lines' => [['description' => 'Bahan lain', 'account_id' => Account::query()->where('code', '1301')->value('id'),
                'quantity' => '1', 'unit_price' => '9000000', 'amount' => '9000000']],
        ], $this->finance), $this->finance);

        $jual->issue($jual->create([
            'customer_id' => $pelanggan->id, 'invoice_date' => '2026-10-10',
            'description' => 'Katering berfaktur pajak',
            'has_tax_invoice' => true, 'tax_amount' => '330000',
            'tax_invoice_no' => '010.000-26.00000055', 'tax_invoice_date' => '2026-10-10',
            'lines' => [['description' => 'Katering', 'account_id' => Account::query()->where('code', '4101')->value('id'),
                'quantity' => '1', 'unit_price' => '3000000', 'amount' => '3000000']],
        ], $this->finance), $this->finance);

        $tabel = app(VatRecapReport::class)->build(
            CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

        $ringkas = collect($tabel->summary)->keyBy('label');
        expect($ringkas['PPN Keluaran']['value'])->toBe('330000.00')
            ->and($ringkas['PPN Masukan']['value'])->toBe('110000.00')
            ->and($ringkas['Selisih']['value'])->toBe('220000.00');

        // Pembelian 9 juta tanpa faktur pajak tidak muncul sama sekali.
        expect(collect($tabel->rows)->pluck('party')->filter()->count())->toBe(2);
    });
});

it('menyebut hari yang datanya belum lengkap, bukan merata-ratanya', function () {
    /*
     * Tanggalnya relatif terhadap hari ini, bukan tetap: papan kelengkapan sengaja TIDAK memeriksa
     * hari yang belum dijalani, jadi uji bertanggal tetap akan berubah artinya begitu tanggal itu
     * lewat — dan gagal pada hari yang tidak ada hubungannya dengan perubahan kode.
     */
    $menggantung = CarbonImmutable::now(config('app.display_timezone'))->subDays(2);

    Factory::tenant($this->company, function () use ($menggantung): void {
        app(JournalService::class)->create([
            'journal_date' => $menggantung->format('Y-m-d'), 'description' => 'Jurnal yang belum diposting',
            'lines' => [
                ['account_id' => Account::query()->where('code', '1110')->value('id'), 'debit' => '100000', 'credit' => '0'],
                ['account_id' => Account::query()->where('code', '3101')->value('id'), 'debit' => '0', 'credit' => '100000'],
            ],
        ], $this->finance);

        // Rentangnya sengaja melewati hari ini, supaya perilaku "hari yang belum dijalani" ikut teruji.
        $tabel = app(CompletenessBoard::class)->build($menggantung->subDays(2), $menggantung->addDays(4));

        expect($tabel->rows)->toHaveCount(7);

        $hariMenggantung = collect($tabel->rows)
            ->first(fn (array $r) => str_contains((string) $r['date'], $menggantung->translatedFormat('d M Y')));
        expect($hariMenggantung['journals_posted'])->toContain('menggantung');

        /*
         * Hanya SATU hari yang disebut belum lengkap. Empat hari lain tidak punya transaksi apa pun,
         * dan hari tanpa transaksi bukan hari yang bolong — papan yang berteriak setiap hari berhenti
         * dibaca dalam sepekan, dan itu kegagalan yang lebih buruk daripada tidak punya papan.
         */
        $ringkas = collect($tabel->summary)->keyBy('label');
        expect($ringkas['Hari belum lengkap']['value'])->toBe('1')
            // Lima hari sampai hari ini diperiksa; dua hari ke depan belum dijalani.
            ->and($ringkas['Hari diperiksa']['value'])->toBe('5');

        $lusa = collect($tabel->rows)->first(fn (array $r) => str_contains((string) $r['date'],
            $menggantung->addDays(4)->translatedFormat('d M Y')));
        expect($lusa['closed'])->toBe(CompletenessBoard::NOT_APPLICABLE);

        /*
         * Dan hari ini TETAP diperiksa — bukan dianggap masa depan karena beda zona waktu. Pembedanya
         * kolom rekonsiliasi: hari yang diperiksa menyebutnya "belum" (—), hari yang belum dijalani
         * menyebutnya tidak berlaku (·).
         */
        $hariIni = collect($tabel->rows)->first(fn (array $r) => str_contains((string) $r['date'],
            CarbonImmutable::now(config('app.display_timezone'))->translatedFormat('d M Y')));
        expect($hariIni['bank'])->toBe(CompletenessBoard::PENDING)
            ->and($lusa['bank'])->toBe(CompletenessBoard::NOT_APPLICABLE);
    });
});
