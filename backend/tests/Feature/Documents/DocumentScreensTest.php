<?php

use App\Filament\Pages\Documents\VerificationQueuePage;
use App\Filament\Resources\ApprovalRuleResource\Pages\EditApprovalRule;
use App\Filament\Resources\ApprovalRuleResource\Pages\ListApprovalRules;
use App\Filament\Resources\PaymentAdviceResource\Pages\ListPaymentAdvices;
use App\Filament\Resources\PaymentRequestResource\Pages\CreatePaymentRequest;
use App\Filament\Resources\PaymentRequestResource\Pages\ListPaymentRequests;
use App\Filament\Resources\PaymentRequestResource\Pages\ViewPaymentRequest;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Documents\Application\ApprovalMatrix;
use App\Modules\Documents\Domain\Models\ApprovalRule;
use App\Modules\Documents\Domain\Models\PaymentAdvice;
use App\Modules\Documents\Domain\Models\PaymentRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * Layar dokumen pembayaran (DOC-04, 05, 07, 09).
 *
 * Diuji lewat Livewire, bukan hanya lewat layanannya. Aturan ini lahir dari insiden layar kredensial
 * payment gateway (27 Sep 2026): 24 uji lulus sementara form aslinya meledak, karena tidak satu pun
 * uji melewati jalur yang dipakai manusia.
 *
 * Yang dikejar di sini ada dua: **form benar-benar menyimpan lewat layanan**, dan **tombol yang pasti
 * ditolak tidak pernah ditampilkan**. Yang kedua bukan soal rapi-rapian — tombol yang selalu gagal
 * membuat orang menekannya berkali-kali lalu menyimpulkan sistemnya rusak.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kedai Dokumen');
    $this->outlet = Factory::outlet($this->company, null, ['code' => 'DOK']);
    [$this->manajer] = Factory::staff($this->company, ['outlet_manager'], [$this->outlet->id]);
    [$this->finance] = Factory::staff($this->company, ['finance'], []);
    [$this->finance2] = Factory::staff($this->company, ['finance'], []);
    [$this->admin] = Factory::staff($this->company, ['company_admin'], []);
    [$this->kasir] = Factory::staff($this->company, ['cashier'], [$this->outlet->id]);
    $this->base = "/admin/{$this->company->code}/dokumen";

    Factory::tenant($this->company, function (): void {
        app(ChartOfAccounts::class)->installTemplate();
        app(ApprovalMatrix::class)->installDefaults();
    });
});

afterEach(fn () => app(TenantContext::class)->reset());

function masukDokumen(object $test, User $user): void
{
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $test->actingAs($user, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($test->company);
    app(TenantContext::class)->setTenant($test->company->id);
}

function akunDokumen(object $test, string $code): Account
{
    return Factory::tenant($test->company, fn () => Account::query()->where('code', $code)->firstOrFail());
}

function sppkTersimpan(object $test): PaymentRequest
{
    return Factory::tenant($test->company, fn () => PaymentRequest::query()->latest('created_at')->firstOrFail());
}

it('membuka layar dokumen untuk peran yang berhak', function (string $peran) {
    $user = match ($peran) {
        'finance' => $this->finance,
        'manajer' => $this->manajer,
        default => $this->admin,
    };
    masukDokumen($this, $user);

    foreach (['antrian-verifikasi', 'pengajuan-pembayaran', 'advis-bayar', 'batas-wewenang'] as $halaman) {
        $this->get("{$this->base}/{$halaman}")->assertSuccessful();
    }
})->with(['finance', 'manajer', 'admin']);

it('menutup layar dokumen untuk kasir', function () {
    masukDokumen($this, $this->kasir);

    foreach (['antrian-verifikasi', 'pengajuan-pembayaran', 'advis-bayar', 'batas-wewenang'] as $halaman) {
        $this->get("{$this->base}/{$halaman}")->assertForbidden();
    }
});

it('menyimpan pengajuan lewat form dan menjalankan seluruh siklusnya dari layar', function () {
    masukDokumen($this, $this->manajer);

    Livewire::test(CreatePaymentRequest::class)
        ->fillForm([
            'request_date' => '2026-10-05',
            'outlet_id' => $this->outlet->id,
            'payee_name' => 'CV Sumber Rejeki',
            'amount' => '20000000',
            'expense_account_id' => akunDokumen($this, '6108')->id,
            'description' => 'Perbaikan mesin kopi dan penggantian grinder',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $sppk = sppkTersimpan($this);
    // Nomor dokumen dibuat layanan, bukan form: bukti bahwa form tidak menulis sendiri ke model.
    expect($sppk->number)->toBe('SPPK-2610-0001')
        ->and($sppk->status)->toBe(PaymentRequest::DRAFT);

    // Diajukan oleh pengajunya.
    Livewire::test(ListPaymentRequests::class)
        ->callTableAction('submit', $sppk)->assertHasNoTableActionErrors();
    $sppk = Factory::tenant($this->company, fn () => PaymentRequest::query()->findOrFail($sppk->id));
    expect($sppk->status)->toBe(PaymentRequest::SUBMITTED)
        // Rp20 juta → band 50 juta → dua tanda tangan, dibekukan saat diajukan.
        ->and($sppk->required_levels)->toBe(2);

    /*
     * Pengajunya sendiri tidak diberi tombol Setujui, dan admin entitas pun belum — tingkat 2 tidak
     * boleh menandatangani sebelum tingkat 1.
     */
    Livewire::test(ListPaymentRequests::class)->assertTableActionHidden('approve', $sppk);
    masukDokumen($this, $this->admin);
    Livewire::test(ListPaymentRequests::class)->assertTableActionHidden('approve', $sppk);

    // Tingkat 1: finance.
    masukDokumen($this, $this->finance);
    Livewire::test(ListPaymentRequests::class)
        ->callTableAction('approve', $sppk, ['note' => 'Penawaran sudah dibandingkan.'])
        ->assertHasNoTableActionErrors();
    $sppk = Factory::tenant($this->company, fn () => PaymentRequest::query()->findOrFail($sppk->id));
    expect($sppk->status)->toBe(PaymentRequest::SUBMITTED);

    // Finance tidak boleh menandatangani tingkat 2 juga.
    Livewire::test(ListPaymentRequests::class)->assertTableActionHidden('approve', $sppk);

    // Tingkat 2: admin entitas. Baru di sini ia disetujui lengkap.
    masukDokumen($this, $this->admin);
    Livewire::test(ListPaymentRequests::class)->callTableAction('approve', $sppk)->assertHasNoTableActionErrors();
    $sppk = Factory::tenant($this->company, fn () => PaymentRequest::query()->findOrFail($sppk->id));
    expect($sppk->status)->toBe(PaymentRequest::APPROVED);

    /*
     * Pencairan: hanya yang berizin `payment.pay`. Manajer outlet mengajukan, tetapi tidak
     * mengeluarkan uang — itu dua pekerjaan berbeda, dan di banyak kasus penyalahgunaan justru
     * digabung.
     */
    masukDokumen($this, $this->manajer);
    Livewire::test(ListPaymentRequests::class)->assertTableActionHidden('pay', $sppk);

    masukDokumen($this, $this->finance);
    Livewire::test(ListPaymentRequests::class)
        ->callTableAction('pay', $sppk, [
            'paid_on' => '2026-10-07',
            'amount' => '20000000',
            'bank_account_id' => akunDokumen($this, '1110')->id,
            'reference' => 'TRF-9001',
        ])
        ->assertHasNoTableActionErrors();

    $sppk = Factory::tenant($this->company, fn () => PaymentRequest::query()->findOrFail($sppk->id));
    $advis = Factory::tenant($this->company, fn () => PaymentAdvice::query()->firstOrFail());
    expect($sppk->status)->toBe(PaymentRequest::PAID)
        ->and($advis->number)->toBe('AB-2610-0001');

    // Jurnalnya lahir sebagai draft: uang keluar lewat advis, pembukuannya tetap melewati maker–checker.
    $jurnal = Factory::tenant($this->company, fn () => Journal::query()->findOrFail($advis->journal_id));
    expect($jurnal->status)->toBe(Journal::DRAFT)
        ->and($jurnal->source)->toBe('payment');

    // Dan karena sudah lunas, tombol pencairan kedua hilang.
    Livewire::test(ListPaymentRequests::class)->assertTableActionHidden('pay', $sppk);
});

it('merender layar detail pengajuan beserta jejak tanda tangan dan advisnya', function () {
    masukDokumen($this, $this->manajer);
    Livewire::test(CreatePaymentRequest::class)->fillForm([
        'request_date' => '2026-10-05',
        'payee_name' => 'Dapur Bu Tini',
        'amount' => '3000000',
        'expense_account_id' => akunDokumen($this, '6108')->id,
        'description' => 'Konsumsi pelatihan barista',
    ])->call('create');
    $sppk = sppkTersimpan($this);

    Livewire::test(ListPaymentRequests::class)->callTableAction('submit', $sppk);
    $sppk = Factory::tenant($this->company, fn () => PaymentRequest::query()->findOrFail($sppk->id));
    masukDokumen($this, $this->finance);
    Livewire::test(ListPaymentRequests::class)->callTableAction('approve', $sppk);

    /*
     * Dirender lewat Livewire karena jejak tanda tangan dan daftar advis adalah blade tersendiri:
     * relasi yang salah nama hanya meledak di sana, bukan di layanannya.
     */
    Livewire::test(ViewPaymentRequest::class, ['record' => $sppk->id])
        ->assertSuccessful()
        ->assertSee('Jejak persetujuan')
        ->assertSee('Advis bayar')
        ->assertSee('Lampiran bukti')
        ->assertSee($this->finance->name);
});

it('menampilkan antrian verifikasi hanya kepada penandatangan tingkat berikutnya', function () {
    masukDokumen($this, $this->manajer);
    Livewire::test(CreatePaymentRequest::class)->fillForm([
        'request_date' => '2026-10-05',
        'payee_name' => 'PT Karya Interior',
        'amount' => '28000000',
        'expense_account_id' => akunDokumen($this, '6108')->id,
        'description' => 'Renovasi area outdoor',
    ])->call('create');
    $sppk = sppkTersimpan($this);
    Livewire::test(ListPaymentRequests::class)->callTableAction('submit', $sppk);

    // Finance (tingkat 1) melihatnya di antrian; admin (tingkat 2) belum.
    masukDokumen($this, $this->finance);
    Livewire::test(VerificationQueuePage::class)->assertSuccessful()
        ->assertSee('Menunggu tanda tangan Anda')->assertSee($sppk->number);

    masukDokumen($this, $this->admin);
    Livewire::test(VerificationQueuePage::class)->assertSuccessful()
        ->assertDontSee('Menunggu tanda tangan Anda');
});

describe('batas wewenang (DOC-09)', function () {
    it('memasang matriks bawaan hanya ketika belum ada, lewat tombol di layar', function () {
        // Matriks sudah terpasang di beforeEach, jadi tombolnya tidak boleh ada lagi — memasangnya
        // dua kali akan mengembalikan kebijakan yang sudah disesuaikan ke bawaan tanpa diminta.
        masukDokumen($this, $this->finance);
        Livewire::test(ListApprovalRules::class)->assertTableActionDoesNotExist('defaults');

        expect(Factory::tenant($this->company, fn () => ApprovalRule::query()->count()))->toBe(6);
    });

    it('memindahkan baris matriks, bukan menduplikasinya', function () {
        masukDokumen($this, $this->finance);
        $baris = Factory::tenant($this->company, fn () => ApprovalRule::query()
            ->where('max_amount', '5000000.00')->where('level', 1)->firstOrFail());

        // Band 5 juta diubah menjadi 7 juta.
        Livewire::test(EditApprovalRule::class, ['record' => $baris->id])
            ->fillForm(['max_amount' => '7000000'])
            ->call('save')
            ->assertHasNoFormErrors();

        $sesudah = Factory::tenant($this->company, fn () => ApprovalRule::query()->get());
        expect($sesudah)->toHaveCount(6)
            ->and($sesudah->firstWhere('id', $baris->id)->max_amount)->toBe('7000000.00')
            // Tidak ada baris 5 juta yang tertinggal: matriks yang punya dua jawaban untuk satu
            // pertanyaan adalah matriks yang tidak bisa dipercaya.
            ->and($sesudah->where('max_amount', '5000000.00'))->toHaveCount(0);
    });

    it('menolak baris matriks yang bertabrakan dengan baris lain', function () {
        masukDokumen($this, $this->finance);
        $baris = Factory::tenant($this->company, fn () => ApprovalRule::query()
            ->where('max_amount', '5000000.00')->where('level', 1)->firstOrFail());

        // Dipindahkan ke band 50 juta tingkat 1 — tempat yang sudah terisi.
        Livewire::test(EditApprovalRule::class, ['record' => $baris->id])
            ->fillForm(['max_amount' => '50000000'])
            ->call('save');

        $sesudah = Factory::tenant($this->company, fn () => ApprovalRule::query()->findOrFail($baris->id));
        expect($sesudah->max_amount)->toBe('5000000.00');
    });

    it('menutup pengubahan matriks dari manajer outlet', function () {
        masukDokumen($this, $this->manajer);

        // Manajer boleh melihat kebijakannya — justru supaya tahu pengajuannya butuh tanda tangan
        // siapa — tetapi tidak mengubahnya.
        $this->get("{$this->base}/batas-wewenang")->assertSuccessful();
        Livewire::test(ListApprovalRules::class)->assertTableActionHidden('edit', Factory::tenant(
            $this->company, fn () => ApprovalRule::query()->firstOrFail()));
    });
});

it('tidak menampilkan dokumen entitas lain di layar', function () {
    masukDokumen($this, $this->manajer);
    Livewire::test(CreatePaymentRequest::class)->fillForm([
        'request_date' => '2026-10-05', 'payee_name' => 'CV Sendiri', 'amount' => '1000000',
        'expense_account_id' => akunDokumen($this, '6108')->id, 'description' => 'Belanja sendiri',
    ])->call('create');

    // Konteks entitas dilepas dulu: selama masih terpasang, RLS menolak membaca entitas baru dan
    // pendaftarannya gagal di tempat yang tidak ada hubungannya dengan yang sedang diuji.
    app(TenantContext::class)->reset();
    [$tetangga, $pemilikLain] = Factory::company('Warung Seberang');
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $this->actingAs($pemilikLain, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($tetangga);
    app(TenantContext::class)->setTenant($tetangga->id);

    Livewire::test(ListPaymentRequests::class)->assertCountTableRecords(0);
    Livewire::test(ListPaymentAdvices::class)->assertCountTableRecords(0);
    Livewire::test(ListApprovalRules::class)->assertCountTableRecords(0);
});
