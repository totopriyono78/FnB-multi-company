<?php

use App\Filament\Demo\DemoAccounts;
use App\Filament\Pages\Auth\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Factory;

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Gamatechno Group');
    Factory::system(fn () => $this->owner->forceFill(['email' => 'rina@gtgroup.test', 'password' => DemoAccounts::PASSWORD])->save());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('menampilkan daftar akun demo bila mode demo aktif', function () {
    config(['fnb.demo_login' => true]);

    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('Akun demo')
        ->assertSee('rina@gtgroup.test')
        ->assertSee('Rahasia123')
        ->assertSee('PIN 482915');
});

it('menyembunyikan daftar akun demo bila mode demo mati', function () {
    config(['fnb.demo_login' => false]);

    $this->get('/admin/login')->assertOk()->assertDontSee('Akun demo')->assertDontSee('Rahasia123');
});

it('tidak pernah menampilkan akun demo di produksi', function () {
    config(['fnb.demo_login' => true]);
    app()->detectEnvironment(fn () => 'production');

    expect(DemoAccounts::enabled())->toBeFalse();
});

it('masuk dengan satu klik pada akun demo', function () {
    config(['fnb.demo_login' => true]);

    Livewire::test(Login::class)
        ->call('loginAsDemo', 'rina@gtgroup.test')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertAuthenticatedAs($this->owner);
});

it('menolak klik akun demo bila mode demo mati atau email bukan akun demo', function (bool $enabled, string $email) {
    config(['fnb.demo_login' => $enabled]);

    Livewire::test(Login::class)
        ->call('loginAsDemo', $email)
        ->assertStatus(404);

    $this->assertGuest();
})->with([
    'mode demo mati' => [false, 'rina@gtgroup.test'],
    'email bukan akun demo' => [true, 'orang.lain@contoh.test'],
]);

it('tetap menerapkan pesan gagal bila akun demo belum di-seed', function () {
    config(['fnb.demo_login' => true]);

    Livewire::test(Login::class)
        ->call('loginAsDemo', 'bayu@gtgroup.test')
        ->assertHasErrors(['data.email']);

    $this->assertGuest();
});
