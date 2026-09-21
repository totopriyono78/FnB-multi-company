<?php

namespace Tests\Support;

use App\Modules\Identity\Application\PinService;
use App\Modules\Identity\Application\StaffManager;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\CompanyRegistrar;
use App\Modules\Tenancy\Application\DevicePairingService;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\CompanyStatus;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Pembuat data uji yang menghormati konteks tenant. */
final class Factory
{
    public static function user(array $attrs = []): User
    {
        return self::system(fn () => User::factory()->create($attrs)->refresh());
    }

    /** @return array{0: Company, 1: User} */
    public static function company(string $name = 'Kopi Senja', string $plan = 'pro'): array
    {
        $owner = self::user(['name' => 'Pemilik '.$name]);
        $company = app(CompanyRegistrar::class)->register(['name' => $name], $owner, $plan);
        self::system(fn () => $company->forceFill(['status' => CompanyStatus::Active])->save());

        return [$company->refresh(), $owner];
    }

    public static function brand(Company $company, array $attrs = []): Brand
    {
        return self::tenant($company, fn () => Brand::query()->create($attrs + [
            'code' => 'B'.strtoupper(Str::random(5)),
            'name' => 'Brand '.Str::random(4),
        ]));
    }

    public static function outlet(Company $company, ?Brand $brand = null, array $attrs = []): Outlet
    {
        $brand ??= self::brand($company);

        return self::tenant($company, fn () => Outlet::query()->create($attrs + [
            'brand_id' => $brand->id,
            'code' => 'O'.strtoupper(Str::random(5)),
            'name' => 'Outlet '.Str::random(4),
            'city' => 'Bandung',
        ]));
    }

    private static int $deviceSeq = 0;

    public static function device(Company $company, Outlet $outlet, array $attrs = []): Device
    {
        // Kode berurutan (10–99): kode acak bisa kembar di outlet yang sama sehingga test gagal sesekali.
        self::$deviceSeq = self::$deviceSeq % 90 + 1;

        return self::tenant($company, fn () => Device::query()->create($attrs + [
            'outlet_id' => $outlet->id,
            'code' => 'POS'.(9 + self::$deviceSeq),
            'name' => 'Kasir',
            'type' => 'pos',
        ]));
    }

    /** @return array{0: Device, 1: string} device aktif + token device */
    public static function pairedDevice(Company $company, Outlet $outlet): array
    {
        // Setelah request API di uji yang sama, guard bawaan menjadi sanctum dan masih menyimpan perangkat lain sebagai "user".
        if (config('auth.defaults.guard') !== 'web') {
            app('auth')->forgetGuards();
            app('auth')->shouldUse('web');
        }
        $device = self::device($company, $outlet);
        $code = self::tenant($company, fn () => app(DevicePairingService::class)->issueCode($device)['code']);
        $result = app(DevicePairingService::class)->pair($code, ['platform' => 'android', 'app_version' => '1.0.0']);

        return [$result['device'], $result['token']->plainTextToken];
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $outlets
     * @return array{0: User, 1: CompanyUser}
     */
    public static function staff(Company $company, array $roles, array $outlets = [], ?string $pin = null, array $brands = []): array
    {
        $owner = self::ownerOf($company);

        $member = self::tenant($company, fn () => app(StaffManager::class)->create($owner, [
            'name' => 'Staf '.Str::random(4),
            'email' => Str::lower(Str::random(10)).'@contoh.test',
            'employee_code' => null,
            'roles' => $roles,
            'scopes' => ['outlets' => $outlets, 'brands' => $brands],
            'pin' => $pin,
        ]));

        return [self::system(fn () => User::query()->findOrFail($member->user_id)), $member];
    }

    public static function setPin(Company $company, User $user, string $pin): void
    {
        self::tenant($company, function () use ($user, $pin): void {
            $member = CompanyUser::query()->where('user_id', $user->id)->firstOrFail();
            app(PinService::class)->setPin($member, $pin);
        });
    }

    public static function token(User $user): string
    {
        return self::system(fn () => $user->createToken('test', ['backoffice'])->plainTextToken);
    }

    public static function ownerOf(Company $company): User
    {
        return self::system(fn () => User::query()
            ->whereIn('id', DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.company_id', $company->id)
                ->where('roles.name', 'owner')
                ->select('model_has_roles.model_uuid'))
            ->firstOrFail());
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $fn
     * @return T
     */
    public static function tenant(Company $company, \Closure $fn): mixed
    {
        return app(TenantContext::class)->runAsTenant($company->id, $fn);
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $fn
     * @return T
     */
    public static function system(\Closure $fn): mixed
    {
        return app(TenantContext::class)->runAsSystem($fn);
    }
}
