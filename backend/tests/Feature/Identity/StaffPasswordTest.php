<?php

use App\Filament\Resources\StaffResource\Pages\CreateStaff;
use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Identity\Application\StaffManager;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Factory;

/**
 * Password awal yang diberikan admin (keputusan user 29 Sep 2026).
 *
 * Latarnya: password staf baru sebelumnya hanya bisa dibuat sendiri oleh stafnya lewat tautan
 * email, dan selama SMTP belum diatur, staf baru tidak pernah bisa masuk sama sekali.
 *
 * Yang dijaga uji di sini bukan sekadar "passwordnya tersimpan", melainkan tiga janji yang
 * membuat jalur ini aman dipakai: company tidak bisa menetapkan password akun yang juga dipakai
 * company lain, penerimanya wajib mengganti password sebelum memakai aplikasi, dan nilai
 * passwordnya tidak pernah masuk audit log.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->outlet = Factory::outlet($this->company);
});

function tambahStaf(object $test, array $extra = []): CompanyUser
{
    return Factory::tenant($test->company, fn () => app(StaffManager::class)->create($test->owner, [
        'name' => 'Sari Kasir',
        'email' => $extra['email'] ?? 'sari@contoh.test',
        'roles' => ['cashier'],
        'scopes' => ['outlets' => [$test->outlet->id]],
    ] + $extra));
}

function akun(CompanyUser $member): User
{
    return Factory::system(fn () => User::query()->findOrFail($member->user_id));
}

it('memakai password dari admin dan menandainya wajib diganti', function () {
    $member = tambahStaf($this, ['password' => 'rahasia123']);

    $user = akun($member);
    expect(Hash::check('rahasia123', $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeTrue();
});

it('tidak mengirim tautan reset bila admin sudah memberi password', function () {
    // Justru inti fiturnya: jalur ini harus bekerja tanpa SMTP sama sekali.
    Notification::fake();

    tambahStaf($this, ['password' => 'rahasia123']);

    Notification::assertNothingSent();
});

it('tetap mengirim tautan reset bila kolom passwordnya dikosongkan', function () {
    Notification::fake();

    $member = tambahStaf($this);

    Notification::assertSentTo(
        akun($member),
        ResetPassword::class
    );
    // Password acak yang tidak diketahui siapa pun: tidak ada yang perlu diganti.
    expect(akun($member)->must_change_password)->toBeFalse();
});

it('menolak memberi password untuk email yang sudah punya akun', function () {
    /*
     * Penjagaan terpenting di berkas ini. Email yang sudah terdaftar berarti akunnya milik orang
     * lain — mungkin staf di company lain — dan menetapkan passwordnya dari sini sama saja dengan
     * menyerahkan akun itu beserta seluruh data company tempat ia bekerja.
     */
    [$lain, $pemilikLain] = Factory::company('Warung Seberang');
    $emailLain = $pemilikLain->email;

    expect(fn () => tambahStaf($this, ['email' => $emailLain, 'password' => 'rahasia123']))
        ->toThrow(ValidationException::class);

    // Password pemilik company lain tidak berubah sedikit pun.
    $tetangga = Factory::system(fn () => User::query()->findOrFail($pemilikLain->id));
    expect(Hash::check('rahasia123', $tetangga->password))->toBeFalse()
        ->and($tetangga->must_change_password)->toBeFalse()
        ->and($lain->id)->not->toBe($this->company->id);
});

it('menolak mengatur ulang password akun yang juga dipakai company lain', function () {
    // Akun yang sama diundang ke company kedua; sejak saat itu identitasnya bukan milik satu company.
    $member = tambahStaf($this, ['password' => 'rahasia123']);
    $user = akun($member);

    [$lain, $pemilikLain] = Factory::company('Warung Seberang');
    Factory::tenant($lain, fn () => app(StaffManager::class)->create($pemilikLain, [
        'name' => 'Sari Kasir',
        'email' => $user->email,
        'roles' => ['cashier'],
        'scopes' => ['outlets' => [Factory::outlet($lain)->id]],
    ]));

    expect(fn () => Factory::tenant($this->company, fn () => app(StaffManager::class)
        ->update($this->owner, $member->refresh(), ['password' => 'gantibaru123'])))
        ->toThrow(ValidationException::class);

    expect(Hash::check('rahasia123', akun($member)->password))->toBeTrue();
});

it('mengatur ulang password staf dan mengakhiri sesi lamanya', function () {
    $member = tambahStaf($this, ['password' => 'rahasia123']);

    Factory::tenant($this->company, fn () => app(StaffManager::class)
        ->update($this->owner, $member->refresh(), ['password' => 'gantibaru123']));

    $user = akun($member);
    expect(Hash::check('gantibaru123', $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeTrue()
        /*
         * Password baru tidak ada gunanya bila sesi lamanya masih hidup — dan admin mengatur
         * ulang password justru ketika akunnya sedang bermasalah. `sessions_revoked_at` inilah
         * yang membuat token terbitan lama ditolak untuk company ini (lihat revokeSessions).
         */
        ->and($member->refresh()->sessions_revoked_at)->not->toBeNull();
});

it('tidak pernah menuliskan nilai password ke audit log', function () {
    $member = tambahStaf($this, ['password' => 'rahasia123']);

    $baris = Factory::system(fn () => AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', 'user.password_set')
        ->where('auditable_id', $member->id)
        ->firstOrFail());

    expect($baris->metadata)->toMatchArray(['initial' => true]);
    expect(json_encode($baris->toArray()))->not->toContain('rahasia123');
});

it('membersihkan penanda wajib ganti begitu passwordnya diubah', function () {
    // Berlaku untuk semua jalur ganti password, termasuk halaman profil Filament yang
    // memakai $record->update() polos.
    $member = tambahStaf($this, ['password' => 'rahasia123']);

    Factory::system(fn () => akun($member)->update(['password' => 'pilihansaya99']));

    expect(akun($member)->must_change_password)->toBeFalse();
});

it('menahan staf di halaman profil selama passwordnya masih buatan admin', function () {
    $member = tambahStaf($this, ['password' => 'rahasia123']);
    $user = akun($member);

    $this->actingAs($user)
        ->get("/admin/{$this->company->code}")
        ->assertRedirect('/admin/profile');

    // Halaman profilnya sendiri tentu harus tetap terbuka, kalau tidak ia terkurung.
    $this->actingAs($user)->get('/admin/profile')->assertOk();
});

it('melepaskan staf begitu password barunya tersimpan', function () {
    $member = tambahStaf($this, ['password' => 'rahasia123']);
    $user = akun($member);
    Factory::system(fn () => $user->update(['password' => 'pilihansaya99']));

    $this->actingAs(akun($member))
        ->get("/admin/{$this->company->code}")
        ->assertOk();
});

describe('formulir back-office', function () {
    beforeEach(function () {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->company);
        app(TenantContext::class)->setTenant($this->company->id);
    });

    afterEach(fn () => app(TenantContext::class)->reset());

    it('menyimpan password awal dari formulir tambah staf', function () {
        Livewire::test(CreateStaff::class)
            ->fillForm([
                'name' => 'Sari Kasir',
                'email' => 'sari@contoh.test',
                'roles' => ['cashier'],
                'scope_outlets' => [$this->outlet->id],
                'password' => 'rahasia123',
                'password_confirmation' => 'rahasia123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = Factory::system(fn () => User::query()->where('email', 'sari@contoh.test')->firstOrFail());
        expect(Hash::check('rahasia123', $user->password))->toBeTrue()
            ->and($user->must_change_password)->toBeTrue();
    });

    it('menolak password yang ulangannya tidak sama', function () {
        // Password awal disampaikan lisan ke staf; salah ketik di sini berarti tidak ada yang
        // tahu password sebenarnya, dan tanpa SMTP tidak ada jalan pemulihannya.
        Livewire::test(CreateStaff::class)
            ->fillForm([
                'name' => 'Sari Kasir',
                'email' => 'sari@contoh.test',
                'roles' => ['cashier'],
                'scope_outlets' => [$this->outlet->id],
                'password' => 'rahasia123',
                'password_confirmation' => 'rahasia124',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        expect(Factory::system(fn () => User::query()->where('email', 'sari@contoh.test')->exists()))->toBeFalse();
    });

    it('menolak password yang terlalu lemah', function () {
        Livewire::test(CreateStaff::class)
            ->fillForm([
                'name' => 'Sari Kasir',
                'email' => 'sari@contoh.test',
                'roles' => ['cashier'],
                'scope_outlets' => [$this->outlet->id],
                'password' => 'abc',
                'password_confirmation' => 'abc',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);
    });
});
