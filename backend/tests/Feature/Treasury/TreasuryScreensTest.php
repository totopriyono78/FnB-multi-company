<?php

use App\Filament\Pages\Treasury\BankReconciliationPage;
use App\Filament\Pages\Treasury\CashPositionPage;
use App\Filament\Resources\CashAccountResource\Pages\CreateCashAccount;
use App\Filament\Resources\CashAccountResource\Pages\ListCashAccounts;
use App\Filament\Resources\CashTransactionResource\Pages\CreateCashTransaction;
use App\Filament\Resources\CashTransactionResource\Pages\ListCashTransactions;
use App\Filament\Resources\PaymentRequestResource\Pages\CreatePaymentRequest;
use App\Filament\Resources\PurchaseInvoiceResource\Pages\CreatePurchaseInvoice;
use App\Filament\Resources\PurchaseInvoiceResource\Pages\ListPurchaseInvoices;
use App\Filament\Resources\SalesInvoiceResource\Pages\ListSalesInvoices;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Documents\Domain\Models\PaymentRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Treasury\Domain\Models\CashAccount;
use App\Modules\Treasury\Domain\Models\CashTransaction;
use App\Modules\Treasury\Domain\Models\PaymentRequestInvoice;
use App\Modules\Treasury\Domain\Models\PurchaseInvoice;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * Layar kas, hutang & piutang (Kelompok 5).
 *
 * Diuji lewat Livewire, bukan hanya lewat layanannya: aturan ini lahir dari insiden layar kredensial
 * payment gateway (27 Sep 2026), ketika 24 uji lulus sementara form aslinya meledak karena tidak
 * satu pun uji melewati jalur yang dipakai manusia.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kedai Layar Kas');
    $this->outlet = Factory::outlet($this->company, null, ['code' => 'LYR']);
    [$this->finance] = Factory::staff($this->company, ['finance'], []);
    [$this->finance2] = Factory::staff($this->company, ['finance'], []);
    [$this->manajer] = Factory::staff($this->company, ['outlet_manager'], [$this->outlet->id]);
    [$this->kasir] = Factory::staff($this->company, ['cashier'], [$this->outlet->id]);
    $this->base = "/admin/{$this->company->code}";

    Factory::tenant($this->company, function (): void {
        app(ChartOfAccounts::class)->installTemplate();
        $this->supplier = Supplier::query()->create([
            'code' => 'SUP1', 'name' => 'CV Bahan Segar', 'payment_term_days' => 14,
        ]);
    });
});

afterEach(fn () => app(TenantContext::class)->reset());

function masukKas(object $test, User $user): void
{
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $test->actingAs($user, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($test->company);
    app(TenantContext::class)->setTenant($test->company->id);
}

function akunLayar(object $test, string $code): string
{
    return (string) Factory::tenant($test->company, fn () => Account::query()->where('code', $code)->value('id'));
}

it('membuka layar kas & hutang untuk finance', function () {
    masukKas($this, $this->finance);

    foreach (['kas/posisi', 'kas/rekening', 'kas/mutasi', 'kas/rekonsiliasi', 'kas/kelengkapan',
        'hutang/faktur-pembelian', 'hutang/umur-hutang', 'hutang/rekap-ppn',
        'piutang/pelanggan', 'piutang/tagihan', 'piutang/umur-piutang'] as $halaman) {
        $this->get("{$this->base}/{$halaman}")->assertSuccessful();
    }
});

it('menutup layar kas & hutang dari kasir dan manajer outlet', function (string $peran) {
    $user = $peran === 'kasir' ? $this->kasir : $this->manajer;
    masukKas($this, $user);

    // Manajer outlet mengajukan pembayaran lewat SPPK; ia tidak mengurus kas entitas maupun hutang.
    foreach (['kas/posisi', 'kas/rekening', 'hutang/faktur-pembelian', 'piutang/tagihan'] as $halaman) {
        $this->get("{$this->base}/{$halaman}")->assertForbidden();
    }
})->with(['kasir', 'manajer']);

it('membuat rekening lewat form, lengkap dengan akun buku besarnya', function () {
    masukKas($this, $this->finance);

    Livewire::test(CreateCashAccount::class)
        ->fillForm([
            'code' => 'BCA', 'name' => 'BCA Operasional', 'kind' => CashAccount::BANK,
            'bank_name' => 'BCA', 'account_number' => '1234567890', 'account_holder' => 'PT Kedai',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rekening = Factory::tenant($this->company, fn () => CashAccount::query()->where('code', 'BCA')->firstOrFail());
    // Akunnya dibuatkan layanan, bukan diisi form: bukti bahwa form tidak menulis sendiri ke model.
    expect($rekening->account_id)->not->toBeNull()
        ->and(Factory::tenant($this->company, fn () => Account::query()->findOrFail($rekening->account_id)->code))
        ->toBe('1104');
});

it('mencatat mutasi kas lewat form dan jurnalnya lahir sebagai draft', function () {
    masukKas($this, $this->finance);
    Livewire::test(CreateCashAccount::class)->fillForm([
        'code' => 'BCA', 'name' => 'BCA Operasional', 'kind' => CashAccount::BANK,
        'account_id' => akunLayar($this, '1110'),
    ])->call('create');
    $bank = Factory::tenant($this->company, fn () => CashAccount::query()->where('code', 'BCA')->firstOrFail());

    Livewire::test(CreateCashTransaction::class)
        ->fillForm([
            'kind' => CashTransaction::IN, 'transaction_date' => '2026-10-05',
            'cash_account_id' => $bank->id, 'contra_account_id' => akunLayar($this, '3101'),
            'amount' => '5000000', 'description' => 'Setoran modal awal pemilik',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $mutasi = Factory::tenant($this->company, fn () => CashTransaction::query()->firstOrFail());
    expect($mutasi->number)->toBe('KM-2610-0001');

    $jurnal = Factory::tenant($this->company, fn () => Journal::query()->findOrFail($mutasi->journal_id));
    expect($jurnal->status)->toBe(Journal::DRAFT)
        ->and($jurnal->source)->toBe('cash');

    // Posisi kas belum berubah: jurnal draft bukan uang, dan layarnya mengatakannya apa adanya.
    masukKas($this, $this->finance);
    $posisi = Livewire::test(CashPositionPage::class)->set('to', '2026-10-31');
    expect($posisi->instance()->table()->totals['balance'])->toBe('0.00');
});

it('menyimpan faktur pembelian lewat form dan menerbitkannya dari daftar', function () {
    masukKas($this, $this->finance);

    Livewire::test(CreatePurchaseInvoice::class)
        ->fillForm([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => '2026-10-05',
            'supplier_invoice_no' => 'INV-001',
            'description' => 'Belanja bahan baku mingguan',
            'has_tax_invoice' => false,
            'lines' => [[
                'description' => 'Kopi arabika 10 kg', 'account_id' => akunLayar($this, '1301'),
                'quantity' => '10', 'unit_price' => '120000', 'amount' => '1200000',
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $faktur = Factory::tenant($this->company, fn () => PurchaseInvoice::query()->firstOrFail());
    expect($faktur->number)->toBe('FB-2610-0001')
        ->and($faktur->status)->toBe(PurchaseInvoice::DRAFT)
        // Jatuh tempo dihitung dari termin supplier, bukan diisi orang.
        ->and($faktur->due_date->format('Y-m-d'))->toBe('2026-10-19');

    Livewire::test(ListPurchaseInvoices::class)->callTableAction('issue', $faktur)->assertHasNoTableActionErrors();

    $terbit = Factory::tenant($this->company, fn () => PurchaseInvoice::query()->findOrFail($faktur->id));
    expect($terbit->status)->toBe(PurchaseInvoice::ISSUED)
        ->and($terbit->journal_id)->not->toBeNull();

    // Setelah diterbitkan, tombol Terbitkan hilang — tombol yang pasti gagal membuat orang mengira
    // sistemnya rusak.
    Livewire::test(ListPurchaseInvoices::class)->assertTableActionHidden('issue', $terbit);
});

it('menyimpan faktur yang ditunjuk SPPK lewat form, bukan hanya di layanannya', function () {
    masukKas($this, $this->finance);

    // Satu faktur yang sudah terbit, siap dilunasi.
    Livewire::test(CreatePurchaseInvoice::class)->fillForm([
        'supplier_id' => $this->supplier->id, 'invoice_date' => '2026-10-05',
        'supplier_invoice_no' => 'INV-LINK', 'description' => 'Faktur untuk diuji pelunasannya',
        'has_tax_invoice' => false,
        'lines' => [['description' => 'Bahan', 'account_id' => akunLayar($this, '1301'),
            'quantity' => '1', 'unit_price' => '1200000', 'amount' => '1200000']],
    ])->call('create');
    $faktur = Factory::tenant($this->company, fn () => PurchaseInvoice::query()->firstOrFail());
    Livewire::test(ListPurchaseInvoices::class)->callTableAction('issue', $faktur);

    /*
     * Inilah jalur yang pernah putus tanpa ketahuan: pilihan faktur di form SPPK sempat dipasangi
     * ->dehydrated(false), sehingga isiannya tidak pernah sampai ke penyimpan. Uji layanan tetap
     * hijau karena ia memanggil attachInvoices() langsung; hanya jalur yang dipakai manusia yang
     * menunjukkannya.
     */
    Livewire::test(CreatePaymentRequest::class)
        ->fillForm([
            'request_date' => '2026-10-19',
            'supplier_id' => $this->supplier->id,
            'invoice_ids' => [$faktur->id],
            'amount' => '1200000',
            'expense_account_id' => akunLayar($this, '2101'),
            'description' => 'Pelunasan faktur INV-LINK',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $sppk = Factory::tenant($this->company, fn () => PaymentRequest::query()->firstOrFail());
    $tautan = Factory::tenant($this->company, fn () => PaymentRequestInvoice::query()
        ->where('payment_request_id', $sppk->id)->get());

    expect($tautan)->toHaveCount(1)
        ->and($tautan->first()->purchase_invoice_id)->toBe($faktur->id)
        // Seluruh sisa hutangnya dialokasikan; pembayaran yang lebih kecil diatur lewat advisnya.
        ->and($tautan->first()->amount)->toBe('1200000.00');
});

it('tidak menampilkan data kas entitas lain di layar', function () {
    masukKas($this, $this->finance);
    Livewire::test(CreateCashAccount::class)->fillForm([
        'code' => 'BCA', 'name' => 'BCA Operasional', 'account_id' => akunLayar($this, '1110'),
    ])->call('create');

    app(TenantContext::class)->reset();
    [$tetangga, $pemilikLain] = Factory::company('Warung Seberang');
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $this->actingAs($pemilikLain, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($tetangga);
    app(TenantContext::class)->setTenant($tetangga->id);

    Livewire::test(ListCashAccounts::class)->assertCountTableRecords(0);
    Livewire::test(ListCashTransactions::class)->assertCountTableRecords(0);
    Livewire::test(ListPurchaseInvoices::class)->assertCountTableRecords(0);
    Livewire::test(ListSalesInvoices::class)->assertCountTableRecords(0);
});

it('merender layar rekonsiliasi walau belum ada rekening koran yang diimpor', function () {
    masukKas($this, $this->finance);

    Livewire::test(BankReconciliationPage::class)
        ->assertSuccessful()
        // Layar kosong pun harus mengatakan apa gunanya ia ada.
        ->assertSee('Belum ada rekening koran yang diimpor');
});
