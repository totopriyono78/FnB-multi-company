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
 * Data demo realistis untuk pengembangan & pilot internal. Semua orang dan usaha fiktif.
 * Password semua akun demo: Rahasia123 — jangan dipakai di produksi.
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
                ['email' => 'platform@fnbcloud.test'],
                ['name' => 'Tim Operasional FnB Cloud', 'password' => self::PASSWORD],
            );
            $admin->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();
        });

        $this->seedKopiNusantara($context);
        $this->seedDapurBuRatna($context);

        // Staf demo memakai password yang sama agar mudah dicoba (hanya non-produksi).
        $context->runAsSystem(function (): void {
            User::query()->where('email', 'like', '%.test')->where('is_platform_admin', false)->get()
                ->each(fn (User $u) => $u->forceFill(['password' => self::PASSWORD, 'email_verified_at' => now()])->save());
        });
    }

    private function seedKopiNusantara(TenantContext $context): void
    {
        $owner = $this->user('Rina Hartono', 'rina@kopinusantara.test', '6281211112222');
        $company = $this->company($owner, 'PT Kopi Nusantara Sejahtera', 'Jakarta Selatan', 'pro', [
            'legal_name' => 'PT Kopi Nusantara Sejahtera',
            'npwp' => '012345678901000',
            'address' => 'Jl. Kemang Raya No. 18',
            'province' => 'DKI Jakarta',
            'postal_code' => '12730',
            'phone' => '0217199876',
        ]);

        $context->runAsTenant($company->id, function () use ($owner): void {
            $kopi = $this->brand('KTJ', 'Kopi Tepi Jalan');
            $roti = $this->brand('RB88', 'Roti Bakar 88');

            $kemang = $this->outlet($kopi, 'KMG', 'Kopi Tepi Jalan Kemang', [
                'address' => 'Jl. Kemang Raya No. 18, Bangka, Mampang Prapatan',
                'city' => 'Jakarta Selatan', 'province' => 'DKI Jakarta', 'postal_code' => '12730',
                'latitude' => '-6.2607130', 'longitude' => '106.8134500', 'phone' => '0217199876',
                'tax_rate' => '10', 'rounding_unit' => 100,
                'opening_hours' => $this->hours('07:00', '22:00'),
            ]);
            $dago = $this->outlet($kopi, 'DGO', 'Kopi Tepi Jalan Dago', [
                'address' => 'Jl. Ir. H. Juanda No. 102, Lebakgede, Coblong',
                'city' => 'Bandung', 'province' => 'Jawa Barat', 'postal_code' => '40132',
                'latitude' => '-6.8845620', 'longitude' => '107.6135200', 'phone' => '0222503311',
                'tax_rate' => '10', 'rounding_unit' => 100,
                'opening_hours' => $this->hours('08:00', '23:00'),
                'business_day_cutoff' => '03:00',
            ]);
            $tebet = $this->outlet($roti, 'TBT', 'Roti Bakar 88 Tebet', [
                'address' => 'Jl. Tebet Raya No. 45, Tebet Timur',
                'city' => 'Jakarta Selatan', 'province' => 'DKI Jakarta', 'postal_code' => '12820',
                'tax_rate' => '10', 'service_charge_rate' => '5', 'order_mode' => 'dine_in',
                'stock_deduction_trigger' => 'on_kitchen', 'rounding_unit' => 500,
                'opening_hours' => $this->hours('16:00', '02:00'),
            ]);

            $this->device($kemang, 'POS01', 'Kasir depan', 'pos', true);
            $this->device($kemang, 'POS02', 'Kasir drive-thru', 'pos', true);
            $this->device($kemang, 'KDS01', 'Layar bar', 'kds', false);
            $this->device($dago, 'POS01', 'Kasir utama', 'pos', true);
            $this->device($tebet, 'POS01', 'Kasir', 'pos', false);

            $this->staff($owner, $this->member('Bayu Pratama', 'bayu@kopinusantara.test', 'HO-001', ['company_admin']));
            $this->staff($owner, $this->member('Dewi Lestari', 'dewi@kopinusantara.test', 'KMG-001', ['outlet_manager'], [$kemang->id], '482915'));
            $this->staff($owner, $this->member('Andi Saputra', 'andi@kopinusantara.test', 'KMG-002', ['cashier'], [$kemang->id], '7351'));
            $this->staff($owner, $this->member('Siti Nurhaliza', 'siti@kopinusantara.test', 'KMG-003', ['cashier'], [$kemang->id], '9024'));
            $this->staff($owner, $this->member('Made Wirawan', 'made@kopinusantara.test', 'KMG-004', ['kitchen'], [$kemang->id]));
            $this->staff($owner, $this->member('Yohanes Siregar', 'yohanes@kopinusantara.test', 'DGO-001', ['outlet_manager'], [$dago->id], '615283'));
            $this->staff($owner, $this->member('Putri Maharani', 'putri@kopinusantara.test', 'DGO-002', ['cashier'], [$dago->id], '3867'));
            $this->staff($owner, $this->member('Hendra Gunawan', 'hendra@kopinusantara.test', 'TBT-001', ['cashier'], [$tebet->id], '5172'));
            $this->staff($owner, $this->member('Lina Kusuma', 'lina@kopinusantara.test', 'HO-002', ['finance']));
            $this->staff($owner, $this->member('Rudi Hartanto', 'rudi@kopinusantara.test', 'HO-003', ['warehouse']));

            app(PinService::class)->setPin(CompanyUser::query()->where('user_id', $owner->id)->firstOrFail(), '802614');

            $menu = new DemoMenuSeeder;
            $menu->kopiTepiJalan($owner, $kopi, $kemang, $dago);
            $menu->rotiBakar88($owner, $roti);

            $userId = fn (string $email) => User::query()->where('email', $email)->value('id');
            $find = fn (string $email) => User::query()->findOrFail($userId($email));
            $inventory = new DemoInventorySeeder;
            $inventory->kopiTepiJalan($owner, $kemang, $dago, $find('dewi@kopinusantara.test'), $find('rudi@kopinusantara.test'));
            (new DemoSalesSeeder)->kemang($kemang, $find('dewi@kopinusantara.test'), $find('andi@kopinusantara.test'), $find('siti@kopinusantara.test'));
            (new DemoReportSeeder)->run([
                'kemang' => $kemang, 'dago' => $dago, 'owner' => $owner,
                'kemangManager' => $find('dewi@kopinusantara.test'),
                'kemangCashiers' => [$find('andi@kopinusantara.test'), $find('siti@kopinusantara.test')],
                'dagoManager' => $find('yohanes@kopinusantara.test'), 'dagoCashier' => $find('putri@kopinusantara.test'),
                'warehouse' => $find('rudi@kopinusantara.test'),
            ]);
            $inventory->afterSales($kemang, $find('rudi@kopinusantara.test'));
        });
    }

    private function seedDapurBuRatna(TenantContext $context): void
    {
        $owner = $this->user('Ratna Wulandari', 'ratna@dapurburatna.test', '6285733334444');
        $company = $this->company($owner, 'CV Dapur Bu Ratna', 'Semarang', 'basic', [
            'legal_name' => 'CV Dapur Bu Ratna',
            'address' => 'Jl. Fatmawati No. 7, Tlogosari',
            'province' => 'Jawa Tengah',
            'postal_code' => '50196',
        ]);

        $context->runAsTenant($company->id, function () use ($owner): void {
            $brand = $this->brand('WBR', 'Warung Bu Ratna');
            $outlet = $this->outlet($brand, 'TLG', 'Warung Bu Ratna Tlogosari', [
                'address' => 'Jl. Fatmawati No. 7, Tlogosari Kulon, Pedurungan',
                'city' => 'Semarang', 'province' => 'Jawa Tengah', 'postal_code' => '50196',
                'tax_rate' => '10', 'tax_inclusive' => true,
                'opening_hours' => $this->hours('06:00', '15:00'),
            ]);
            $this->device($outlet, 'POS01', 'Kasir', 'pos', true);

            $this->staff($owner, $this->member('Agus Setiawan', 'agus@dapurburatna.test', 'TLG-001', ['cashier'], [$outlet->id], '2749'));

            (new DemoMenuSeeder)->warungBuRatna($owner, $brand);
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
