<?php

use App\Filament\Resources\PaymentGatewayAccountResource\Pages\CreatePaymentGatewayAccount;
use App\Filament\Resources\PaymentGatewayAccountResource\Pages\EditPaymentGatewayAccount;
use App\Modules\Payment\Domain\Models\PaymentGatewayAccount;
use App\Modules\Tenancy\Application\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * Siapa yang boleh mengatur kredensial merchant payment gateway.
 *
 * Sengaja lebih ketat daripada daftar metode pembayaran per outlet (yang dijaga `outlet.manage`):
 * yang diatur di layar ini adalah rekening merchant mana yang menerima uang pelanggan, jadi
 * dibatasi `company.manage` — pemilik dan admin company.
 *
 * Diuji lewat permintaan HTTP, bukan panggilan `canAccess()` langsung: izin spatie memakai
 * team = company, jadi hanya permintaan yang melewati middleware tenant yang mencerminkan
 * perilaku sebenarnya.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->kemang = Factory::outlet($this->company, null, ['code' => 'KMG']);
    $this->alamat = "/admin/{$this->company->code}/payment-gateway";
});

it('menolak alamatnya untuk peran tanpa izin kelola company', function (string $role) {
    [$user] = Factory::staff($this->company, [$role], [$this->kemang->id]);

    $this->actingAs($user)->get($this->alamat)->assertForbidden();
})->with([
    'kasir' => ['cashier'],
    // Manajer outlet boleh menyalakan QRIS & mengatur MDR, tetapi tidak boleh memindahkan
    // tujuan dananya ke merchant lain.
    'manajer outlet' => ['outlet_manager'],
    'manajer brand' => ['brand_manager'],
    'finance' => ['finance'],
    'gudang' => ['warehouse'],
    'dapur' => ['kitchen'],
]);

it('menyembunyikan menunya dari manajer outlet', function () {
    [$manajer] = Factory::staff($this->company, ['outlet_manager'], [$this->kemang->id]);

    $this->actingAs($manajer)->get("/admin/{$this->company->code}")
        ->assertOk()
        ->assertDontSee('Payment Gateway');
});

it('memberi akses pada pemilik', function () {
    $this->actingAs($this->owner)->get($this->alamat)->assertOk()->assertSee('Payment Gateway');
});

it('memberi akses pada admin company', function () {
    [$admin] = Factory::staff($this->company, ['company_admin']);

    $this->actingAs($admin)->get($this->alamat)->assertOk();
});

/*
 * Form isian kredensial, diuji lewat komponen Livewire-nya.
 *
 * Ditambahkan 27 Sep 2026 setelah bug yang lolos: model memakai `$guarded = ['*']`, sehingga layar
 * "Tambah kredensial" meledak dengan MassAssignmentException. Uji sebelumnya hanya memeriksa izin
 * akses (HTTP GET) dan membuat baris lewat `forceFill`, jadi jalur yang dipakai pengguna sungguhan
 * — Filament mengisi model lewat `fill()` — tidak pernah dilewati sama sekali.
 */
describe('form kredensial', function () {
    beforeEach(function () {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
        app(TenantContext::class)->setTenant($this->company->id);
    });

    afterEach(fn () => app(TenantContext::class)->reset());

    it('menyimpan kredensial baru dari form', function () {
        Livewire::test(CreatePaymentGatewayAccount::class)
            ->fillForm([
                'provider' => 'aino',
                'outlet_id' => $this->kemang->id,
                'environment' => PaymentGatewayAccount::SANDBOX,
                'merchant_code' => 'Gamatechno_test',
                'secret_key' => 'kunci-rahasia',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $akun = PaymentGatewayAccount::query()->where('merchant_code', 'Gamatechno_test')->firstOrFail();

        expect($akun->company_id)->toBe($this->company->id)
            ->and($akun->outlet_id)->toBe($this->kemang->id)
            ->and($akun->secret_key)->toBe('kunci-rahasia');

        // Tersimpan terenkripsi, bukan apa adanya.
        $mentah = (string) DB::table('payment_gateway_accounts')->where('id', $akun->id)->value('secret_key');
        expect($mentah)->not->toContain('kunci-rahasia');
    });

    it('menyimpan kredensial tingkat company saat outlet dikosongkan', function () {
        Livewire::test(CreatePaymentGatewayAccount::class)
            ->fillForm([
                'provider' => 'aino',
                'environment' => PaymentGatewayAccount::SANDBOX,
                'merchant_code' => 'Merchant_Company',
                'secret_key' => 'kunci-company',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(PaymentGatewayAccount::query()->where('merchant_code', 'Merchant_Company')->value('outlet_id'))->toBeNull();
    });

    it('tidak mengirim kunci tersimpan ke peramban, dan mengosongkannya berarti tidak mengganti', function () {
        $akun = PaymentGatewayAccount::query()->create([
            'provider' => 'aino',
            'outlet_id' => $this->kemang->id,
            'environment' => PaymentGatewayAccount::SANDBOX,
            'merchant_code' => 'Merchant_Awal',
            'secret_key' => 'kunci-lama',
            'is_active' => true,
        ]);

        Livewire::test(EditPaymentGatewayAccount::class, ['record' => $akun->getKey()])
            // Kunci lama tidak boleh ikut terkirim ke form.
            ->assertFormSet(['secret_key' => null, 'merchant_code' => 'Merchant_Awal'])
            ->fillForm(['merchant_code' => 'Merchant_Baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $akun->refresh();
        expect($akun->merchant_code)->toBe('Merchant_Baru')
            ->and($akun->secret_key)->toBe('kunci-lama');
    });

    it('mengganti kunci ketika kolomnya diisi', function () {
        $akun = PaymentGatewayAccount::query()->create([
            'provider' => 'aino',
            'outlet_id' => $this->kemang->id,
            'environment' => PaymentGatewayAccount::SANDBOX,
            'merchant_code' => 'Merchant_Awal',
            'secret_key' => 'kunci-lama',
            'is_active' => true,
        ]);

        Livewire::test(EditPaymentGatewayAccount::class, ['record' => $akun->getKey()])
            ->fillForm(['secret_key' => 'kunci-baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($akun->refresh()->secret_key)->toBe('kunci-baru');
    });
});
