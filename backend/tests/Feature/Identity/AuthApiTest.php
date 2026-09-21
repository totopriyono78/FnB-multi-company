<?php

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Company;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Support\Factory;

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    Factory::system(fn () => $this->owner->forceFill(['email' => 'rina@kopi.test', 'phone' => '6281211112222'])->save());
});

it('login dengan email atau nomor HP (FR-AUTH-01)', function (string $login) {
    $response = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'Rahasia123', 'device_name' => 'Owner App']);

    $response->assertOk()
        ->assertJsonPath('data.user.email', 'rina@kopi.test')
        ->assertJsonPath('data.user.companies.0.id', $this->company->id);
    assertStandardEnvelope($response);
    expect($response->json('data.token'))->toBeString()
        ->and($response->json('data.expires_at'))->not->toBeNull();

    $this->getJson('/api/v1/auth/me', bearer($response->json('data.token')))
        ->assertOk()->assertJsonPath('data.name', $this->owner->name);
})->with(['RINA@kopi.test', '0812-1111-2222', '+62 812 1111 2222']);

it('menolak password salah tanpa membedakan akun tidak dikenal', function () {
    $wrong = $this->postJson('/api/v1/auth/login', ['login' => 'rina@kopi.test', 'password' => 'salah', 'device_name' => 'x']);
    $unknown = $this->postJson('/api/v1/auth/login', ['login' => 'tidakada@kopi.test', 'password' => 'salah', 'device_name' => 'x']);

    $wrong->assertUnprocessable();
    $unknown->assertUnprocessable();
    expect($wrong->json('errors.0.message'))->toBe($unknown->json('errors.0.message'));
});

it('mengunci akun setelah 5 kali gagal (FR-AUTH-08)', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/login', ['login' => 'rina@kopi.test', 'password' => 'salah'.$i, 'device_name' => 'x'], ['REMOTE_ADDR' => "10.0.0.{$i}"])
            ->assertUnprocessable();
    }

    $locked = $this->postJson('/api/v1/auth/login', ['login' => 'rina@kopi.test', 'password' => 'Rahasia123', 'device_name' => 'x'], ['REMOTE_ADDR' => '10.0.1.1']);
    $locked->assertStatus(423);

    $this->travel(16)->minutes();
    $this->postJson('/api/v1/auth/login', ['login' => 'rina@kopi.test', 'password' => 'Rahasia123', 'device_name' => 'x'], ['REMOTE_ADDR' => '10.0.1.2'])
        ->assertOk();
});

it('membatasi laju percobaan login (NFR-SEC-05)', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/login', ['login' => 'budi@kopi.test', 'password' => 'x', 'device_name' => 'x']);
    }

    $this->postJson('/api/v1/auth/login', ['login' => 'budi@kopi.test', 'password' => 'x', 'device_name' => 'x'])
        ->assertStatus(429);
});

it('logout mencabut token yang dipakai', function () {
    $token = Factory::token($this->owner);

    $this->postJson('/api/v1/auth/logout', [], bearer($token))->assertOk();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/auth/me', bearer($token))->assertUnauthorized();
});

it('menolak token kedaluwarsa (FR-AUTH-10)', function () {
    $token = Factory::system(fn () => $this->owner->createToken('uji', ['backoffice'], now()->addMinute())->plainTextToken);

    $this->travel(2)->minutes();
    $this->getJson('/api/v1/auth/me', bearer($token))->assertUnauthorized();
});

it('mendaftarkan company baru secara mandiri beserta role bawaan (FR-TEN-01)', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'company_name' => 'Bakso Pak Kumis',
        'city' => 'Malang',
        'name' => 'Slamet Riyadi',
        'email' => 'Slamet@BaksoKumis.test',
        'phone' => '0813 3344 5566',
        'password' => 'Bakso2026',
        'password_confirmation' => 'Bakso2026',
        'accept_terms' => true,
    ]);

    $response->assertCreated();
    $companyId = $response->json('data.company_id');
    $token = $response->json('data.token');

    $company = Factory::system(fn () => Company::query()->findOrFail($companyId));
    expect($company->status->value)->toBe('trial')
        ->and($company->plan_id)->not->toBeNull()
        ->and($company->subscription_ends_at->isFuture())->toBeTrue();

    $user = Factory::system(fn () => User::query()->where('email', 'slamet@baksokumis.test')->firstOrFail());
    expect($user->phone)->toBe('6281333445566');

    // Pemilik langsung bisa membuat brand.
    $this->postJson('/api/v1/brands', ['code' => 'BPK', 'name' => 'Bakso Pak Kumis'], bearer($token) + ['X-Company-Id' => $companyId])
        ->assertCreated();
    $roles = $this->getJson('/api/v1/roles', bearer($token) + ['X-Company-Id' => $companyId])->assertOk();
    expect(collect($roles->json('data'))->pluck('name')->all())
        ->toContain('owner', 'company_admin', 'brand_manager', 'outlet_manager', 'cashier', 'kitchen', 'warehouse', 'finance');
});

it('memvalidasi pendaftaran', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'company_name' => '',
        'name' => 'X',
        'email' => 'rina@kopi.test',
        'password' => 'pendek',
        'password_confirmation' => 'beda',
    ]);

    $response->assertUnprocessable();
    expect(collect($response->json('errors'))->pluck('field')->unique()->values()->all())
        ->toContain('company_name', 'email', 'password', 'accept_terms');
});

it('mengirim tautan reset password tanpa membocorkan email terdaftar', function () {
    Notification::fake();

    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'rina@kopi.test'])->assertOk();
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@kopi.test'])->assertOk();

    expect($known->json('data.message'))->toBe($unknown->json('data.message'));
    Notification::assertSentTo($this->owner, ResetPassword::class);
});

it('mereset password dan membuka kunci akun', function () {
    Factory::system(fn () => $this->owner->forceFill(['failed_login_attempts' => 5, 'locked_until' => now()->addMinutes(10)])->save());
    $oldToken = Factory::token($this->owner);
    $resetToken = Factory::system(fn () => Password::createToken($this->owner));

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $resetToken,
        'email' => 'rina@kopi.test',
        'password' => 'KopiBaru2026',
        'password_confirmation' => 'KopiBaru2026',
    ])->assertOk();

    $this->getJson('/api/v1/auth/me', bearer($oldToken))->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['login' => 'rina@kopi.test', 'password' => 'KopiBaru2026', 'device_name' => 'x'])->assertOk();

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'token-palsu', 'email' => 'rina@kopi.test', 'password' => 'KopiBaru2027', 'password_confirmation' => 'KopiBaru2027',
    ])->assertUnprocessable();
});

it('tidak menampilkan company yang ditangguhkan pada daftar company user', function () {
    Factory::system(fn () => $this->company->forceFill(['status' => 'suspended'])->save());

    $this->getJson('/api/v1/auth/me', asMember($this->owner))
        ->assertOk()
        ->assertJsonCount(0, 'data.companies');
});
