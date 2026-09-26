<?php

use App\Filament\Resources\BrandResource\Pages\CreateBrand;
use App\Filament\Resources\BrandResource\Pages\ListBrands;
use App\Filament\Resources\DeviceResource\Pages\ListDevices;
use App\Filament\Resources\OutletResource\Pages\ListOutlets;
use App\Filament\Widgets\OperationalAlerts;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Device;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Factory;

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    [$this->other, $this->otherOwner] = Factory::company('Warung Bu Ratna');
});

it('menampilkan halaman login dalam Bahasa Indonesia', function () {
    $this->get('/admin/login')->assertOk()->assertSee('Email atau nomor HP');
});

it('membuka halaman profil pengguna yang berada di luar lingkup tenant', function () {
    // Rute ini (/admin/profile, tanpa {tenant}) pernah memunculkan layar 500: tata letak penuh
    // membangun sidebar, lalu tautan halaman pertama memanggil getUrl() tanpa tenant aktif.
    $this->actingAs($this->owner)->get('/admin/profile')->assertOk();
});

it('mengarahkan tamu ke halaman login', function () {
    $this->get("/admin/{$this->company->code}")->assertRedirect('/admin/login');
});

it('membuka halaman utama back-office untuk anggota company', function (string $path) {
    $this->actingAs($this->owner)
        ->get("/admin/{$this->company->code}{$path}")
        ->assertOk();
})->with([
    'ringkasan' => [''],
    'brand' => ['/brands'],
    'outlet' => ['/outlets'],
    'perangkat' => ['/devices'],
    'staf' => ['/staf'],
    'audit log' => ['/audit-log'],
    'profil usaha' => ['/profile'],
]);

it('menolak membuka back-office company lain (404)', function () {
    $this->actingAs($this->owner)
        ->get("/admin/{$this->other->code}/brands")
        ->assertNotFound();
});

it('menyembunyikan menu yang tidak diizinkan untuk kasir', function () {
    $outlet = Factory::outlet($this->company);
    [$cashier] = Factory::staff($this->company, ['cashier'], [$outlet->id]);

    $this->actingAs($cashier)->get("/admin/{$this->company->code}/brands")->assertForbidden();
    $this->actingAs($cashier)->get("/admin/{$this->company->code}/audit-log")->assertForbidden();
});

describe('komponen Livewire', function () {
    beforeEach(function () {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
        app(TenantContext::class)->setTenant($this->company->id);
    });

    afterEach(fn () => app(TenantContext::class)->reset());

    it('hanya menampilkan brand milik tenant aktif', function () {
        $mine = Brand::query()->create(['code' => 'KTJ', 'name' => 'Kopi Tepi Jalan']);
        $foreign = Factory::brand($this->other, ['code' => 'WBR', 'name' => 'Warung Bu Ratna']);
        app(TenantContext::class)->setTenant($this->company->id);

        Livewire::test(ListBrands::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$foreign]);
    });

    it('membuat brand dari form dan memvalidasi kode unik per company', function () {
        Livewire::test(CreateBrand::class)
            ->fillForm(['code' => 'rb88', 'name' => 'Roti Bakar 88'])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Brand::query()->where('code', 'RB88')->exists())->toBeTrue();

        Livewire::test(CreateBrand::class)
            ->fillForm(['code' => 'RB88', 'name' => 'Duplikat'])
            ->call('create')
            ->assertHasFormErrors(['code' => 'unique']);
    });

    it('membatasi daftar outlet, perangkat, dan brand untuk manajer outlet (FR-AUTH-06)', function () {
        $brandA = Factory::brand($this->company, ['code' => 'KTJ']);
        $brandB = Factory::brand($this->company, ['code' => 'RB88']);
        $kemang = Factory::outlet($this->company, $brandA, ['code' => 'KMG']);
        $tebet = Factory::outlet($this->company, $brandB, ['code' => 'TBT']);
        $mine = Factory::device($this->company, $kemang, ['code' => 'POS01']);
        $other = Factory::device($this->company, $tebet, ['code' => 'POS02']);
        [$manager] = Factory::staff($this->company, ['outlet_manager'], [$kemang->id]);

        $this->actingAs($manager);
        app(TenantContext::class)->setTenant($this->company->id);

        Livewire::test(ListOutlets::class)
            ->assertCanSeeTableRecords([$kemang])
            ->assertCanNotSeeTableRecords([$tebet]);
        Livewire::test(ListDevices::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    });

    it('menerapkan mode baca-saja langganan di back-office (FR-TEN-08)', function () {
        Factory::system(fn () => $this->company->forceFill(['subscription_ends_at' => now()->subDays(30)])->save());
        app(TenantContext::class)->setTenant($this->company->id);

        $this->get("/admin/{$this->company->code}/brands/create")->assertForbidden();
        $this->get("/admin/{$this->company->code}/brands")->assertOk();
    });

    it('menghitung angka ringkasan sesuai cakupan manajer outlet', function () {
        $kemang = Factory::outlet($this->company, null, ['code' => 'KMG']);
        $tebet = Factory::outlet($this->company, null, ['code' => 'TBT']);
        Factory::device($this->company, $kemang, ['code' => 'POS01']);
        Factory::tenant($this->company, fn () => Device::query()->create(['outlet_id' => $tebet->id, 'code' => 'POS02', 'name' => 'Kasir', 'type' => 'pos'])
            ->forceFill(['status' => 'active', 'pending_sync_count' => 7])->save());
        [$manager] = Factory::staff($this->company, ['outlet_manager'], [$kemang->id]);

        $this->actingAs($manager);
        app(TenantContext::class)->setTenant($this->company->id);

        Livewire::test(OperationalAlerts::class)
            ->assertSeeInOrder(['Outlet aktif', '1'])
            ->assertSeeInOrder(['Transaksi belum tersinkron', '0']);
    });

    it('menampilkan daftar perangkat beserta status koneksi', function () {
        $outlet = Factory::outlet($this->company);
        [$device] = Factory::pairedDevice($this->company, $outlet);
        app(TenantContext::class)->setTenant($this->company->id);

        Livewire::test(ListDevices::class)
            ->assertCanSeeTableRecords([$device])
            ->assertSee('Online');
    });
});
