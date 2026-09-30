<?php

use App\Filament\Pages\Accounting\JournalMappingPage;
use App\Filament\Pages\Accounting\TrialBalancePage;
use App\Filament\Resources\AccountResource\Pages\CreateAccount;
use App\Filament\Resources\AccountResource\Pages\ListAccounts;
use App\Filament\Resources\JournalResource\Pages\CreateJournal;
use App\Filament\Resources\JournalResource\Pages\ListJournals;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\JournalMap;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Domain\Models\JournalLine;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Factory;
use Tests\Support\Menu;

/**
 * Layar akuntansi: siapa boleh melihat, siapa boleh mengubah, dan apakah formnya benar-benar
 * menyimpan lewat layanan (bukan langsung ke model).
 *
 * Uji Livewire yang benar-benar memanggil form adalah aturan yang lahir dari insiden layar
 * kredensial payment gateway (27 Sep 2026): 24 uji lulus sementara form aslinya meledak, karena
 * tidak satu pun uji melewati jalur yang dipakai manusia.
 *
 * Peran: **finance** mengelola pembukuan (`accounting.manage`), **owner** hanya melihat
 * (`accounting.view`) — sesuai matriks izin SRS §12.1 yang sudah berlaku sejak 26 Sep 2026.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->outlet = Factory::outlet($this->company, null, ['code' => 'KMG']);
    [$this->finance] = Factory::staff($this->company, ['finance'], [$this->outlet->id]);
    $this->base = "/admin/{$this->company->code}/pembukuan";
});

afterEach(fn () => app(TenantContext::class)->reset());

function masukAkuntansi(object $test, User $user): void
{
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $test->actingAs($user, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($test->company);
    app(TenantContext::class)->setTenant($test->company->id);
}

function pasangTemplatePembukuan(object $test): void
{
    Factory::tenant($test->company, fn () => app(ChartOfAccounts::class)->installTemplate());
}

function akunPembukuanKode(object $test, string $code): Account
{
    return Factory::tenant($test->company, fn () => Account::query()->where('code', $code)->firstOrFail());
}

it('membuka layar akuntansi untuk finance dan pemilik', function (string $peran) {
    $user = $peran === 'finance' ? $this->finance : $this->owner;
    masukAkuntansi($this, $user);

    foreach (['bagan-akun', 'jurnal', 'neraca-saldo', 'buku-besar', 'periode'] as $halaman) {
        $this->get("{$this->base}/{$halaman}")->assertSuccessful();
    }
})->with(['finance', 'pemilik']);

it('menutup layar akuntansi untuk peran tanpa izin', function (string $role) {
    [$user] = Factory::staff($this->company, [$role], [$this->outlet->id]);
    masukAkuntansi($this, $user);

    $this->get("{$this->base}/bagan-akun")->assertForbidden();
    $this->get("{$this->base}/jurnal")->assertForbidden();
    $this->get("{$this->base}/neraca-saldo")->assertForbidden();
})->with(['kasir' => ['cashier'], 'manajer outlet' => ['outlet_manager'], 'gudang' => ['warehouse']]);

it('memasang template bagan akun lewat tombol di layar', function () {
    masukAkuntansi($this, $this->finance);

    Livewire::test(ListAccounts::class)->callAction('installTemplate');

    expect(Factory::tenant($this->company, fn () => Account::query()->count()))->toBeGreaterThan(40);
});

it('menyimpan akun baru lewat form, bukan langsung ke model', function () {
    pasangTemplatePembukuan($this);
    masukAkuntansi($this, $this->finance);

    Livewire::test(CreateAccount::class)
        ->fillForm([
            'code' => '6150',
            'name' => 'Beban Kebersihan',
            'type' => Account::EXPENSE,
            'is_postable' => true,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $akun = akunPembukuanKode($this, '6150');
    // Saldo normal tidak diisi pengguna — layanan yang menetapkannya dari jenis akun.
    expect($akun->normal_balance)->toBe('debit')
        ->and($akun->is_system)->toBeFalse();
});

it('menyimpan jurnal seimbang lewat form dan memposting dari daftar', function () {
    pasangTemplatePembukuan($this);
    masukAkuntansi($this, $this->finance);

    Livewire::test(CreateJournal::class)
        ->fillForm([
            'journal_date' => '2026-10-05',
            'description' => 'Setoran modal awal',
            'lines' => [
                ['account_id' => akunPembukuanKode($this, '1110')->id, 'debit' => '5000000', 'credit' => '0'],
                ['account_id' => akunPembukuanKode($this, '3101')->id, 'debit' => '0', 'credit' => '5000000'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $jurnal = Factory::tenant($this->company, fn () => Journal::query()->firstOrFail());
    expect($jurnal->status)->toBe(Journal::DRAFT)
        ->and($jurnal->number)->toBe('JU-2610-0001')
        ->and(Factory::tenant($this->company, fn () => JournalLine::query()->count()))->toBe(2);

    masukAkuntansi($this, $this->finance);
    Livewire::test(ListJournals::class)->callTableAction('post', $jurnal)->assertHasNoTableActionErrors();

    expect(Factory::tenant($this->company, fn () => Journal::query()->findOrFail($jurnal->id))->status)->toBe(Journal::POSTED);
});

it('menolak jurnal timpang lewat form tanpa menyisakan baris', function () {
    pasangTemplatePembukuan($this);
    masukAkuntansi($this, $this->finance);

    Livewire::test(CreateJournal::class)
        ->fillForm([
            'journal_date' => '2026-10-05',
            'description' => 'Jurnal yang tidak seimbang',
            'lines' => [
                ['account_id' => akunPembukuanKode($this, '1110')->id, 'debit' => '5000000', 'credit' => '0'],
                ['account_id' => akunPembukuanKode($this, '3101')->id, 'debit' => '0', 'credit' => '4000000'],
            ],
        ])
        ->call('create');

    // Tidak ada jurnal setengah jadi yang tertinggal — penolakan terjadi sebelum apa pun ditulis.
    expect(Factory::tenant($this->company, fn () => Journal::query()->count()))->toBe(0)
        ->and(Factory::tenant($this->company, fn () => JournalLine::query()->count()))->toBe(0);
});

it('menyembunyikan aksi posting dan jurnal balik dari pemilik', function () {
    pasangTemplatePembukuan($this);
    masukAkuntansi($this, $this->finance);
    Livewire::test(CreateJournal::class)->fillForm([
        'journal_date' => '2026-10-05',
        'description' => 'Setoran modal awal',
        'lines' => [
            ['account_id' => akunPembukuanKode($this, '1110')->id, 'debit' => '1000000', 'credit' => '0'],
            ['account_id' => akunPembukuanKode($this, '3101')->id, 'debit' => '0', 'credit' => '1000000'],
        ],
    ])->call('create');
    $jurnal = Factory::tenant($this->company, fn () => Journal::query()->firstOrFail());

    /*
     * Pemilik boleh membaca pembukuan tetapi tidak memposting: posting adalah pernyataan bahwa
     * angkanya benar, dan itu pekerjaan finance.
     */
    masukAkuntansi($this, $this->owner);
    Livewire::test(ListJournals::class)->assertTableActionHidden('post', $jurnal);
});

it('menampilkan neraca saldo yang seimbang di layar', function () {
    pasangTemplatePembukuan($this);
    masukAkuntansi($this, $this->finance);
    Livewire::test(CreateJournal::class)->fillForm([
        'journal_date' => '2026-10-05',
        'description' => 'Setoran modal awal',
        'lines' => [
            ['account_id' => akunPembukuanKode($this, '1110')->id, 'debit' => '5000000', 'credit' => '0'],
            ['account_id' => akunPembukuanKode($this, '3101')->id, 'debit' => '0', 'credit' => '5000000'],
        ],
    ])->call('create');
    $jurnal = Factory::tenant($this->company, fn () => Journal::query()->firstOrFail());
    masukAkuntansi($this, $this->finance);
    Livewire::test(ListJournals::class)->callTableAction('post', $jurnal);

    masukAkuntansi($this, $this->finance);
    $halaman = Livewire::test(TrialBalancePage::class, ['from' => '2026-10-01', 'to' => '2026-10-31']);
    $halaman->set('from', '2026-10-01')->set('to', '2026-10-31');
    $tabel = $halaman->instance()->table();

    expect($tabel)->not->toBeNull()
        ->and($tabel->totals['debit'])->toBe('5000000.00')
        ->and($tabel->totals['credit'])->toBe('5000000.00')
        ->and($tabel->totals['closing_debit'])->toBe($tabel->totals['closing_credit']);
});

it('tidak menampilkan bagan akun milik company lain di layar', function () {
    pasangTemplatePembukuan($this);

    [$tetangga, $pemilikLain] = Factory::company('Warung Seberang');
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $this->actingAs($pemilikLain, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($tetangga);
    app(TenantContext::class)->setTenant($tetangga->id);

    Livewire::test(ListAccounts::class)->assertCountTableRecords(0);
});

describe('pemetaan akun jurnal otomatis (ACC-10)', function () {
    it('mengisi pemetaan bawaan dan menyimpan pengecualian per kategori menu', function () {
        pasangTemplatePembukuan($this);
        $brand = Factory::brand($this->company, ['code' => 'BMN']);
        $kategori = Menu::category($this->company, $brand, 'Minuman');
        masukAkuntansi($this, $this->finance);

        $layar = Livewire::test(JournalMappingPage::class);
        // Sebelum diisi, layar mengatakan apa adanya bahwa jurnal otomatis belum bisa jalan.
        expect($layar->instance()->missing())->not->toBeEmpty();

        $layar->callAction('installDefaults');
        expect(Livewire::test(JournalMappingPage::class)->instance()->missing())->toBe([]);

        // Pendapatan minuman dipisahkan ke akun 4102.
        Livewire::test(JournalMappingPage::class)
            ->set("data.kategori.{$kategori->id}", akunPembukuanKode($this, '4102')->id)
            ->call('save');

        $akun = Factory::tenant($this->company, fn () => app(JournalMap::class)->account(JournalMap::REVENUE, $kategori->id));
        expect($akun->code)->toBe('4102');

        // Dikosongkan kembali = ikut aturan bawaan, bukan tanpa akun.
        Livewire::test(JournalMappingPage::class)
            ->set("data.kategori.{$kategori->id}", null)
            ->call('save');

        expect(Factory::tenant($this->company, fn () => app(JournalMap::class)->account(JournalMap::REVENUE, $kategori->id)->code))->toBe('4103');
    });

    it('menampilkan dan menyimpan slot pembayaran yang namanya memuat titik', function () {
        pasangTemplatePembukuan($this);
        masukAkuntansi($this, $this->finance);
        Livewire::test(JournalMappingPage::class)->callAction('installDefaults');

        /*
         * Slot pembayaran bernama `payment.cash`. Titiknya sempat dibaca Filament sebagai jalur
         * bersarang, sehingga layar menampilkan slot kosong padahal pemetaannya ada — dan finance
         * akan mengisinya ulang tanpa tahu apa yang sebenarnya salah.
         */
        Livewire::test(JournalMappingPage::class)
            ->assertSet('data.payment__cash', akunPembukuanKode($this, '1101')->id)
            ->set('data.payment__cash', akunPembukuanKode($this, '1102')->id)
            ->call('save');

        expect(Factory::tenant($this->company, fn () => app(JournalMap::class)->account(JournalMap::paymentSlot('cash'))->code))->toBe('1102');
    });

    it('pemilik boleh melihat pemetaan tetapi tidak mengubahnya', function () {
        pasangTemplatePembukuan($this);
        Factory::tenant($this->company, fn () => app(JournalMap::class)->installDefaults());
        masukAkuntansi($this, $this->owner);

        $this->get("{$this->base}/pemetaan-akun")->assertSuccessful();

        Livewire::test(JournalMappingPage::class)
            ->set('data.'.JournalMap::TAX, akunPembukuanKode($this, '2202')->id)
            ->call('save');

        // Akun pajak tetap 2201: layar yang hanya dapat dilihat tidak boleh bisa menulis.
        expect(Factory::tenant($this->company, fn () => app(JournalMap::class)->account(JournalMap::TAX)->code))->toBe('2201');
    });

    it('menolak akun induk sebagai tujuan pemetaan', function () {
        pasangTemplatePembukuan($this);
        masukAkuntansi($this, $this->finance);

        Livewire::test(JournalMappingPage::class)
            ->set('data.'.JournalMap::REVENUE, akunPembukuanKode($this, '4100')->id)
            ->call('save');

        expect(Factory::tenant($this->company, fn () => app(JournalMap::class)->account(JournalMap::REVENUE, null, false)))->toBeNull();
    });
});
