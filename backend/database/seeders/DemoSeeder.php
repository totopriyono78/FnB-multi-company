<?php

namespace Database\Seeders;

use App\Filament\Demo\DemoAccounts;
use App\Modules\Identity\Application\PinService;
use App\Modules\Identity\Application\StaffManager;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\CompanyRegistrar;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\CompanyStatus;
use App\Modules\Tenancy\Domain\DeviceStatus;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;

/**
 * Data demo Gamatechno Group untuk peragaan dan pilot internal. Semua orang fiktif.
 *
 *   Gamatechno Group
 *   ├─ Hamzah Coffee: Kaliurang, Prawirotaman
 *   └─ Hamzah Resto:  Ikan Bakar Seturan (ikan per gram), Jl. Magelang (resto umum)
 *
 * Semua akun memakai domain @gtgroup.test dan password Rahasia123 — jangan dipakai di produksi.
 */
class DemoSeeder extends Seeder
{
    private const PASSWORD = DemoAccounts::PASSWORD;

    public function run(): void
    {
        Notification::fake(); // Jangan kirim email reset ke akun demo.

        $context = app(TenantContext::class);

        $context->runAsSystem(function (): void {
            $admin = User::query()->firstOrCreate(
                ['email' => 'platform@gtgroup.test'],
                ['name' => 'Tim Operasional FnB Cloud', 'password' => self::PASSWORD],
            );
            $admin->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();
        });

        $this->seedGamatechno($context);

        // Staf demo memakai password yang sama agar mudah dicoba (hanya non-produksi).
        $context->runAsSystem(function (): void {
            User::query()->where('email', 'like', '%@gtgroup.test')->where('is_platform_admin', false)->get()
                ->each(fn (User $u) => $u->forceFill(['password' => self::PASSWORD, 'email_verified_at' => now()])->save());
        });
    }

    private function seedGamatechno(TenantContext $context): void
    {
        $owner = $this->user('Rina Hartono', 'rina@gtgroup.test', '6281211112222');
        $company = $this->company($owner, 'Gamatechno Group', 'Yogyakarta', 'pro', [
            'legal_name' => 'Gamatechno Group',
            'npwp' => '012345678901000',
            'address' => 'Jl. Kaliurang Km 5,6 No. 21, Caturtunggal, Depok, Sleman',
            'province' => 'DI Yogyakarta',
            'postal_code' => '55281',
            'phone' => '0274588990',
        ]);

        $context->runAsTenant($company->id, function () use ($owner): void {
            $coffee = $this->brand('HMC', 'Hamzah Coffee');
            $resto = $this->brand('HMR', 'Hamzah Resto');

            $kaliurang = $this->outlet($coffee, 'KLU', 'Hamzah Coffee Kaliurang', [
                'address' => 'Jl. Kaliurang Km 5,6 No. 21, Caturtunggal, Depok',
                'city' => 'Sleman', 'province' => 'DI Yogyakarta', 'postal_code' => '55281',
                'latitude' => '-7.7581200', 'longitude' => '110.3818600', 'phone' => '0274588991',
                'tax_name' => 'PB1', 'tax_rate' => '10', 'rounding_unit' => 100, 'table_count' => 18,
                'opening_hours' => $this->hours('07:00', '22:00'),
            ]);
            $prawirotaman = $this->outlet($coffee, 'PRW', 'Hamzah Coffee Prawirotaman', [
                'address' => 'Jl. Prawirotaman No. 18, Brontokusuman, Mergangsan',
                'city' => 'Yogyakarta', 'province' => 'DI Yogyakarta', 'postal_code' => '55153',
                'latitude' => '-7.8193400', 'longitude' => '110.3697100', 'phone' => '0274388112',
                'tax_name' => 'PB1', 'tax_rate' => '10', 'rounding_unit' => 100, 'table_count' => 24,
                'opening_hours' => $this->hours('08:00', '23:00'),
                'business_day_cutoff' => '03:00',
            ]);
            $ikanBakar = $this->outlet($resto, 'SRT', 'Hamzah Resto Ikan Bakar Seturan', [
                'address' => 'Jl. Seturan Raya No. 45, Caturtunggal, Depok',
                'city' => 'Sleman', 'province' => 'DI Yogyakarta', 'postal_code' => '55281',
                'latitude' => '-7.7695300', 'longitude' => '110.4090200', 'phone' => '0274489900',
                'tax_name' => 'PB1', 'tax_rate' => '10', 'service_charge_rate' => '5', 'rounding_unit' => 100,
                // Tamu makan dulu, membayar di kasir setelah selesai.
                'order_mode' => 'dine_in', 'stock_deduction_trigger' => 'on_kitchen', 'table_count' => 20,
                'opening_hours' => $this->hours('10:00', '22:00'),
            ]);
            $umum = $this->outlet($resto, 'MGL', 'Hamzah Resto Jl. Magelang', [
                'address' => 'Jl. Magelang Km 5 No. 12, Sinduadi, Mlati',
                'city' => 'Sleman', 'province' => 'DI Yogyakarta', 'postal_code' => '55284',
                'latitude' => '-7.7589100', 'longitude' => '110.3614800', 'phone' => '0274566770',
                'tax_name' => 'PB1', 'tax_rate' => '10', 'service_charge_rate' => '5', 'rounding_unit' => 500,
                'order_mode' => 'dine_in', 'stock_deduction_trigger' => 'on_kitchen', 'table_count' => 24,
                'opening_hours' => $this->hours('10:00', '21:30'),
            ]);

            $this->device($kaliurang, 'POS01', 'Kasir depan', 'pos', true);
            $this->device($kaliurang, 'POS02', 'Kasir drive-thru', 'pos', true);
            $this->device($kaliurang, 'KDS01', 'Layar bar', 'kds', false);
            $this->device($prawirotaman, 'POS01', 'Kasir utama', 'pos', true);
            $this->device($ikanBakar, 'POS01', 'Kasir depan', 'pos', true);
            $this->device($ikanBakar, 'POS02', 'Kasir samping', 'pos', false);
            $this->device($umum, 'POS01', 'Kasir', 'pos', true);
            $this->device($umum, 'KDS01', 'Layar dapur', 'kds', false);

            // Kantor pusat.
            $this->staff($owner, $this->member('Bayu Pratama', 'bayu@gtgroup.test', 'HO-001', ['company_admin']));
            $this->staff($owner, $this->member('Lina Kusuma', 'lina@gtgroup.test', 'HO-002', ['finance']));
            $this->staff($owner, $this->member('Rudi Hartanto', 'rudi@gtgroup.test', 'HO-003', ['warehouse']));
            // Hamzah Coffee Kaliurang.
            $this->staff($owner, $this->member('Dewi Lestari', 'dewi@gtgroup.test', 'KLU-001', ['outlet_manager'], [$kaliurang->id], '482915'));
            $this->staff($owner, $this->member('Andi Saputra', 'andi@gtgroup.test', 'KLU-002', ['cashier'], [$kaliurang->id], '7351'));
            $this->staff($owner, $this->member('Siti Nurhaliza', 'siti@gtgroup.test', 'KLU-003', ['cashier'], [$kaliurang->id], '9024'));
            $this->staff($owner, $this->member('Made Wirawan', 'made@gtgroup.test', 'KLU-004', ['kitchen'], [$kaliurang->id]));
            // Hamzah Coffee Prawirotaman.
            $this->staff($owner, $this->member('Yohanes Siregar', 'yohanes@gtgroup.test', 'PRW-001', ['outlet_manager'], [$prawirotaman->id], '615283'));
            $this->staff($owner, $this->member('Putri Maharani', 'putri@gtgroup.test', 'PRW-002', ['cashier'], [$prawirotaman->id], '3867'));
            // Hamzah Resto Ikan Bakar Seturan.
            $this->staff($owner, $this->member('Siti Aminah', 'aminah@gtgroup.test', 'SRT-001', ['outlet_manager'], [$ikanBakar->id], '260418'));
            $this->staff($owner, $this->member('Yusuf Maulana', 'yusuf@gtgroup.test', 'SRT-002', ['cashier'], [$ikanBakar->id], '4719'));
            // Hamzah Resto Jl. Magelang.
            $this->staff($owner, $this->member('Rizky Ramadhan', 'rizky@gtgroup.test', 'MGL-001', ['outlet_manager'], [$umum->id], '5836'));
            $this->staff($owner, $this->member('Hendra Gunawan', 'hendra@gtgroup.test', 'MGL-002', ['cashier'], [$umum->id], '5172'));
            $this->staff($owner, $this->member('Joko Susanto', 'joko@gtgroup.test', 'MGL-003', ['kitchen'], [$umum->id]));

            app(PinService::class)->setPin(CompanyUser::query()->where('user_id', $owner->id)->firstOrFail(), '802614');

            $menu = new DemoMenuSeeder;
            $menu->hamzahCoffee($owner, $coffee, $kaliurang, $prawirotaman);
            $menu->hamzahResto($owner, $resto, $ikanBakar, $umum);

            $userId = fn (string $email) => User::query()->where('email', $email)->value('id');
            $find = fn (string $email) => User::query()->findOrFail($userId($email));
            $inventory = new DemoInventorySeeder;
            $inventory->hamzahCoffee($owner, $kaliurang, $prawirotaman, $find('dewi@gtgroup.test'), $find('rudi@gtgroup.test'));
            (new DemoSalesSeeder)->kaliurang($kaliurang, $find('dewi@gtgroup.test'), $find('andi@gtgroup.test'), $find('siti@gtgroup.test'));
            (new DemoReportSeeder)->run([
                'kemang' => $kaliurang, 'dago' => $prawirotaman, 'owner' => $owner,
                'kemangManager' => $find('dewi@gtgroup.test'),
                'kemangCashiers' => [$find('andi@gtgroup.test'), $find('siti@gtgroup.test')],
                'dagoManager' => $find('yohanes@gtgroup.test'), 'dagoCashier' => $find('putri@gtgroup.test'),
                'warehouse' => $find('rudi@gtgroup.test'),
                'restoUmum' => $umum, 'restoUmumManager' => $find('rizky@gtgroup.test'), 'restoUmumCashier' => $find('hendra@gtgroup.test'),
                'restoIkan' => $ikanBakar, 'restoIkanManager' => $find('aminah@gtgroup.test'), 'restoIkanCashier' => $find('yusuf@gtgroup.test'),
            ]);
            $inventory->afterSales($kaliurang, $find('rudi@gtgroup.test'));
        });
    }

    /**
     * Buat staf bila belum menjadi anggota company (seeder aman diulang).
     *
     * @param  array<string, mixed>  $data
     */
    private function staff(User $owner, array $data): void
    {
        $exists = CompanyUser::query()
            ->whereHas('user', fn ($q) => $q->where('email', $data['email']))
            ->exists();
        if (! $exists) {
            app(StaffManager::class)->create($owner, $data);
        }
    }

    private function user(string $name, string $email, string $phone): User
    {
        return app(TenantContext::class)->runAsSystem(function () use ($name, $email, $phone): User {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $name, 'phone' => $phone, 'password' => self::PASSWORD,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user;
        });
    }

    /** @param  array<string, mixed>  $profile */
    private function company(User $owner, string $name, string $city, string $plan, array $profile): Company
    {
        $existing = app(TenantContext::class)->runAsSystem(fn () => Company::query()->where('name', $name)->first());
        if ($existing !== null) {
            return $existing;
        }

        $company = app(CompanyRegistrar::class)->register(['name' => $name, 'city' => $city, 'email' => $owner->email], $owner, $plan);

        app(TenantContext::class)->runAsTenant($company->id, function () use ($company, $profile): void {
            $company->fill($profile)->save();
            $company->forceFill([
                'status' => CompanyStatus::Active,
                'subscription_ends_at' => now()->addYear(),
            ])->save();
        });

        return $company;
    }

    private function brand(string $code, string $name): Brand
    {
        return Brand::query()->firstOrCreate(['code' => $code], ['name' => $name]);
    }

    /** @param  array<string, mixed>  $attrs */
    private function outlet(Brand $brand, string $code, string $name, array $attrs): Outlet
    {
        return Outlet::query()->firstOrCreate(['code' => $code], ['brand_id' => $brand->id, 'name' => $name] + $attrs + [
            'receipt_settings' => ['footer' => 'Terima kasih, sampai jumpa lagi', 'paper_width' => 80, 'show_logo' => true],
        ]);
    }

    private function device(Outlet $outlet, string $code, string $name, string $type, bool $online): void
    {
        $device = Device::query()->firstOrCreate(
            ['outlet_id' => $outlet->id, 'code' => $code],
            ['name' => $name, 'type' => $type],
        );

        if ($online) {
            $device->forceFill([
                'status' => DeviceStatus::Active,
                'platform' => 'android',
                'app_version' => '1.0.0',
                'paired_at' => now()->subDays(3),
                'last_seen_at' => now()->subSeconds(30),
                'last_synced_at' => now()->subMinute(),
            ])->save();
        }
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $outlets
     * @return array<string, mixed>
     */
    private function member(string $name, string $email, string $code, array $roles, array $outlets = [], ?string $pin = null): array
    {
        return [
            'name' => $name,
            'email' => $email,
            'phone' => null,
            'employee_code' => $code,
            'roles' => $roles,
            'scopes' => ['outlets' => $outlets, 'brands' => []],
            'pin' => $pin,
        ];
    }

    /** @return array<string, array{open: string, close: string}> */
    private function hours(string $open, string $close): array
    {
        $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

        return array_fill_keys($days, ['open' => $open, 'close' => $close]);
    }
}
