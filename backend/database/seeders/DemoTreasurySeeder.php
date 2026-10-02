<?php

namespace Database\Seeders;

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Tenancy\Domain\Models\Outlet;
use App\Modules\Treasury\Application\CashAccountService;
use App\Modules\Treasury\Application\CashTransactionService;
use App\Modules\Treasury\Application\PurchaseInvoiceService;
use App\Modules\Treasury\Application\ReceivableService;
use App\Modules\Treasury\Domain\Models\CashAccount;
use App\Modules\Treasury\Domain\Models\CashTransaction;
use App\Modules\Treasury\Domain\Models\Customer;
use App\Modules\Treasury\Domain\Models\PurchaseInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Kas, bank, hutang & piutang untuk peragaan (Kelompok 5).
 *
 * Yang dibuat bukan satu contoh dari tiap jenis, melainkan satu keadaan yang **tidak rapi**: faktur
 * yang sudah lewat tempo, faktur yang baru dibayar sebagian, tagihan keluar yang belum dilunasi,
 * dan jurnal yang masih menunggu diposting. Layar umur hutang dan papan kelengkapan yang hanya
 * pernah dilihat dengan data sempurna selalu tampak baik-baik saja.
 */
class DemoTreasurySeeder extends Seeder
{
    public function run(): void
    {
        // Idempoten: seeder demo dijalankan berulang kali di basis data yang sama.
        if (CashAccount::query()->exists()) {
            return;
        }
        if (Account::query()->doesntExist()) {
            app(ChartOfAccounts::class)->installTemplate();
        }

        $finance = $this->user('lina@gtgroup.test');
        $finance2 = $this->user('farah@gtgroup.test');
        if ($finance === null || $finance2 === null) {
            return;
        }

        $outlet = Outlet::query()->where('code', 'KLU')->value('id');
        $hariIni = CarbonImmutable::now(config('app.display_timezone'))->startOfDay();

        /* ---------- Rekening kas & bank ---------- */
        $akun = app(CashAccountService::class);
        $bank = $akun->create([
            'code' => 'BCA', 'name' => 'BCA Operasional', 'kind' => CashAccount::BANK,
            'bank_name' => 'BCA', 'account_number' => '0451234567', 'account_holder' => 'PT Gamatechno Indonesia',
            'account_id' => Account::query()->where('code', '1110')->value('id'),
        ]);
        $laci = $akun->create([
            'code' => 'LACI-KLU', 'name' => 'Kas Laci Kaliurang', 'kind' => CashAccount::CASH,
            'account_id' => Account::query()->where('code', '1101')->value('id'),
            'outlet_id' => $outlet,
        ]);
        $akun->create([
            'code' => 'KECIL-KLU', 'name' => 'Kas Kecil Kaliurang', 'kind' => CashAccount::CASH,
            'account_id' => Account::query()->where('code', '1102')->value('id'),
            'outlet_id' => $outlet,
        ]);

        /* ---------- Mutasi kas: setoran hasil penjualan, dan satu yang belum diposting ---------- */
        $mutasi = app(CashTransactionService::class);
        $setor = $mutasi->record([
            'kind' => CashTransaction::TRANSFER, 'transaction_date' => $hariIni->subDays(4)->format('Y-m-d'),
            'cash_account_id' => $laci->id, 'counter_cash_account_id' => $bank->id,
            'amount' => '7500000', 'description' => 'Setoran hasil penjualan Kaliurang',
            'reference' => 'STR/2610/001',
        ], $finance);
        $this->postJournal($setor->journal_id, $finance, $finance2);

        // Sengaja dibiarkan sebagai draft: papan kelengkapan harus punya sesuatu untuk dilaporkan.
        $mutasi->record([
            'kind' => CashTransaction::OUT, 'transaction_date' => $hariIni->subDay()->format('Y-m-d'),
            'cash_account_id' => $bank->id,
            'contra_account_id' => Account::query()->where('code', '6103')->value('id'),
            'amount' => '1850000', 'description' => 'Tagihan listrik PLN bulan berjalan',
        ], $finance);

        /* ---------- Hutang usaha ---------- */
        $supplier = Supplier::query()->where('is_active', true)->orderBy('name')->first();
        if ($supplier !== null) {
            $supplier->forceFill([
                'npwp' => '021234567891000', 'is_pkp' => true, 'payment_term_days' => 14,
                'bank_name' => 'Mandiri', 'bank_account_number' => '1370001234567',
                'bank_account_holder' => $supplier->name,
            ])->save();

            $beli = app(PurchaseInvoiceService::class);
            $persediaan = Account::query()->where('code', '1301')->value('id');

            // Lewat jatuh tempo, berfaktur pajak: inilah yang harus menonjol di umur hutang.
            $this->issue($beli, [
                'supplier_id' => $supplier->id,
                'invoice_date' => $hariIni->subDays(45)->format('Y-m-d'),
                'due_date' => $hariIni->subDays(31)->format('Y-m-d'),
                'supplier_invoice_no' => 'INV/0892/26',
                'description' => 'Bahan baku kopi dan susu, pengiriman dua minggu',
                'tax_amount' => '1320000', 'tax_invoice_no' => '010.000-26.00004521',
                'tax_invoice_date' => $hariIni->subDays(45)->format('Y-m-d'),
                'lines' => [
                    ['description' => 'Biji kopi arabika 60 kg', 'account_id' => $persediaan,
                        'quantity' => '60', 'unit_price' => '155000', 'amount' => '9300000'],
                    ['description' => 'Susu UHT 120 liter', 'account_id' => $persediaan,
                        'quantity' => '120', 'unit_price' => '25000', 'amount' => '3000000'],
                ],
            ], $finance, $finance2);

            // Belum jatuh tempo, tanpa faktur pajak.
            $this->issue($beli, [
                'supplier_id' => $supplier->id,
                'invoice_date' => $hariIni->subDays(3)->format('Y-m-d'),
                'supplier_invoice_no' => 'INV/0917/26',
                'description' => 'Perlengkapan kebersihan dan kemasan',
                'has_tax_invoice' => false,
                'lines' => [['description' => 'Kemasan gelas & tutup', 'account_id' => $persediaan,
                    'quantity' => '1', 'unit_price' => '2750000', 'amount' => '2750000']],
            ], $finance, $finance2);

            // Masih draft: belum menjadi hutang, dan papan kelengkapan menyebutnya.
            $beli->create([
                'supplier_id' => $supplier->id,
                'invoice_date' => $hariIni->format('Y-m-d'),
                'description' => 'Tagihan jasa perawatan mesin bulan ini',
                'has_tax_invoice' => false,
                'lines' => [['description' => 'Servis mesin espresso', 'account_id' => Account::query()->where('code', '6108')->value('id'),
                    'quantity' => '1', 'unit_price' => '1250000', 'amount' => '1250000']],
            ], $finance);
        }

        /* ---------- Piutang usaha ---------- */
        $pelanggan = Customer::query()->create([
            'code' => 'PLG-001', 'name' => 'PT Mitra Sejahtera Yogyakarta',
            'contact_name' => 'Bu Ratih', 'phone' => '0274556677', 'npwp' => '031234567892000',
            'payment_term_days' => 30,
        ]);
        $jual = app(ReceivableService::class);
        $pendapatan = Account::query()->where('code', '4103')->value('id');

        $tagihan = $jual->issue($jual->create([
            'customer_id' => $pelanggan->id,
            'invoice_date' => $hariIni->subDays(20)->format('Y-m-d'),
            'outlet_id' => $outlet,
            'description' => 'Katering rapat bulanan, 3 sesi',
            'has_tax_invoice' => true, 'tax_amount' => '990000',
            'tax_invoice_no' => '010.000-26.00000078',
            'tax_invoice_date' => $hariIni->subDays(20)->format('Y-m-d'),
            'lines' => [['description' => 'Paket nasi kotak 3 × 90 porsi', 'account_id' => $pendapatan,
                'quantity' => '270', 'unit_price' => '33333.3333', 'amount' => '9000000']],
        ], $finance), $finance);
        $this->postJournal($tagihan->journal_id, $finance, $finance2);

        // Dilunasi sebagian: sisanya tetap muncul di umur piutang.
        $terima = $jual->receive($tagihan, [
            'cash_account_id' => $bank->id,
            'received_on' => $hariIni->subDays(5)->format('Y-m-d'),
            'amount' => '5000000', 'reference' => 'TRF/IN/2610/004',
        ], $finance);
        $this->postJournal($terima->journal_id, $finance, $finance2);
    }

    /**
     * Terbitkan faktur pembelian berikut jurnalnya yang langsung diposting.
     *
     * @param  array<string, mixed>  $data
     */
    private function issue(PurchaseInvoiceService $service, array $data, User $finance, User $pemeriksa): PurchaseInvoice
    {
        $faktur = $service->issue($service->create($data, $finance), $finance);
        $this->postJournal($faktur->journal_id, $finance, $pemeriksa);

        return $faktur->refresh();
    }

    /** Lewati jalur normal: diajukan satu orang, diposting orang lain (ACC-05). */
    private function postJournal(?string $journalId, User $pengaju, User $pemeriksa): void
    {
        if ($journalId === null) {
            return;
        }
        $service = app(JournalService::class);
        /** @var Journal|null $jurnal */
        $jurnal = Journal::query()->find($journalId);
        if ($jurnal === null || ! $jurnal->isDraft()) {
            return;
        }
        $service->post($service->submit($jurnal, $pengaju)->refresh(), $pemeriksa);
    }

    private function user(string $email): ?User
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        return $user;
    }
}
