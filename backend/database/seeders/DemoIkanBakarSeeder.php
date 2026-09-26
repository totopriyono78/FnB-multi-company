<?php

namespace Database\Seeders;

use App\Filament\Demo\DemoAccounts;
use App\Modules\Catalog\Application\ItemWriter;
use App\Modules\Catalog\Application\ModifierGroupWriter;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\KitchenStation;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use App\Modules\Identity\Application\StaffManager;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\DeviceStatus;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Database\Seeder;

/**
 * Contoh menu resto ikan bakar — outlet uji coba POS (keputusan user 23 Sep 2026).
 *
 * Yang diperagakan di sini:
 *
 * 1. **Ikan dijual per gram.** Tamu memilih ikan, ikannya ditimbang di luar sistem, lalu kasir
 *    memasukkan beratnya. Harga item disimpan sebagai **harga per gram**, sehingga total baris =
 *    harga per gram x berat. Menulis harga per gram (bukan per kg) membuat angka yang diketik kasir
 *    sama persis dengan angka di layar timbangan, tanpa konversi di kepala.
 * 2. **Cara olah dan varian rasa sebagai modifier wajib.** Setiap ikan harus punya satu cara olah
 *    (bakar/goreng) dan satu rasa; rasa tertentu menambah harga karena bumbunya lebih mahal.
 *
 * Dijalankan terpisah dari data demo utama:
 *     php artisan db:seed --class=DemoIkanBakarSeeder
 *
 * Aman diulang: semuanya firstOrCreate / dilewati bila sudah ada.
 */
class DemoIkanBakarSeeder extends Seeder
{
    private const OUTLET = 'BHR';

    /**
     * Harga per gram. Rp 95/gram = Rp 95.000/kg.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}> sku, nama, harga per gram, keterangan
     */
    private const IKAN = [
        ['IKN-NLA', 'Nila Merah', '55', 'Ikan air tawar, daging lembut, ukuran 300-600 gram.'],
        ['IKN-GRM', 'Gurame Segar', '95', 'Gurame kolam, ukuran 500 gram - 1,2 kg.'],
        ['IKN-CMI', 'Cumi Segar', '110', 'Cumi ukuran sedang, cocok dibakar atau digoreng tepung.'],
        ['IKN-KKP', 'Kakap Merah', '130', 'Kakap laut, ukuran 600 gram - 1,5 kg.'],
        ['IKN-BWL', 'Bawal Bintang', '145', 'Bawal laut, daging tebal, ukuran 400-900 gram.'],
        ['IKN-UDG', 'Udang Windu', '165', 'Udang segar ukuran besar, dijual per gram.'],
    ];

    /** @var list<array{0: string, 1: string, 2: string}> sku, nama, harga */
    private const PELENGKAP = [
        ['PLK-NSP', 'Nasi Putih', '6000'],
        ['PLK-LLP', 'Lalapan & Sambal', '10000'],
        ['PLK-KKG', 'Tumis Kangkung', '18000'],
        ['PLK-TAH', 'Tahu Tempe Goreng', '12000'],
        ['PLK-ETM', 'Es Teh Manis', '8000'],
        ['PLK-EJR', 'Es Jeruk Peras', '12000'],
    ];

    public function run(): void
    {
        $context = app(TenantContext::class);
        $company = $context->runAsSystem(fn () => Company::query()->orderBy('created_at')->first());
        if ($company === null) {
            $this->command?->warn('Belum ada company. Jalankan `php artisan db:seed` lebih dulu.');

            return;
        }
        // Pemilik dipakai sebagai pelaku saat membuat staf (StaffManager memeriksa wewenangnya).
        $owner = $context->runAsTenant($company->id, fn () => CompanyUser::query()
            ->with('user')
            ->whereHas('user.roles', fn ($q) => $q->where('name', 'owner'))
            ->first()?->user);

        $context->runAsTenant($company->id, function () use ($owner): void {
            $brand = Brand::query()->firstOrCreate(['code' => 'BHR'], ['name' => 'Bahari Ikan Bakar']);

            $outlet = Outlet::query()->firstOrCreate(['code' => self::OUTLET], [
                'brand_id' => $brand->id,
                'name' => 'Bahari Ikan Bakar Kelapa Gading',
                'address' => 'Jl. Boulevard Raya Blok QJ No. 12, Kelapa Gading',
                'city' => 'Jakarta Utara',
                'province' => 'DKI Jakarta',
                'postal_code' => '14240',
                'phone' => '0214530099',
                'tax_name' => 'PB1',
                'tax_rate' => '10',
                'service_charge_rate' => '5',
                'rounding_unit' => 100,
                // Tamu makan dulu, membayar di kasir setelah selesai.
                'order_mode' => 'dine_in',
                'stock_deduction_trigger' => 'on_kitchen',
                'table_count' => 20,
                'timezone' => 'Asia/Jakarta',
                'receipt_settings' => ['footer' => 'Terima kasih, selamat menikmati', 'paper_width' => 80, 'show_logo' => true],
            ]);

            $this->device($outlet, 'POS01', 'Kasir depan');
            $this->device($outlet, 'POS02', 'Kasir samping');

            $bakaran = KitchenStation::query()->firstOrCreate(['code' => 'BAKARAN'], ['name' => 'Pembakaran', 'sort_order' => 10]);
            $dapur = KitchenStation::query()->firstOrCreate(['code' => 'KITCHEN'], ['name' => 'Dapur', 'sort_order' => 20]);

            $katIkan = MenuCategory::query()->firstOrCreate(
                ['brand_id' => $brand->id, 'name' => 'Ikan & Seafood'],
                ['color' => 'blue', 'sort_order' => 0],
            );
            $katPelengkap = MenuCategory::query()->firstOrCreate(
                ['brand_id' => $brand->id, 'name' => 'Pelengkap'],
                ['color' => 'green', 'sort_order' => 1],
            );

            // Wajib dipilih satu: kasir tidak bisa lupa menanyakannya ke tamu.
            $olah = $this->group($brand, 'Cara Olah', 1, 1, [
                ['Bakar', '0', true],
                ['Goreng', '0'],
            ]);
            // Rasa tertentu menambah harga karena bumbunya berbeda.
            $rasa = $this->group($brand, 'Varian Rasa', 1, 1, [
                ['Biasa', '0', true],
                ['Pedas', '0'],
                ['Asam Manis', '15000'],
                ['Bakar Madu', '20000'],
            ]);

            foreach (self::IKAN as [$sku, $nama, $hargaPerGram, $deskripsi]) {
                $this->item($brand, $katIkan, $sku, $nama, $hargaPerGram, $bakaran->id, [
                    'description' => $deskripsi,
                    'sold_by_weight' => true,
                    'unit' => 'gram',
                    'modifier_group_ids' => [$olah, $rasa],
                ]);
            }

            foreach (self::PELENGKAP as [$sku, $nama, $harga]) {
                $this->item($brand, $katPelengkap, $sku, $nama, $harga, $dapur->id);
            }

            if ($owner instanceof User) {
                $this->staff($owner, 'Yusuf Maulana', 'yusuf@bahariikanbakar.test', 'BHR-001', ['cashier'], $outlet->id, '4719');
                $this->staff($owner, 'Siti Aminah', 'siti.aminah@bahariikanbakar.test', 'BHR-002', ['outlet_manager'], $outlet->id, '260418');
            }
        });

        $this->command?->info('Menu resto ikan bakar siap: outlet BHR, 6 ikan per gram, 6 pelengkap, 2 grup modifier.');
    }

    private function device(Outlet $outlet, string $code, string $name): void
    {
        $device = Device::query()->firstOrCreate(
            ['outlet_id' => $outlet->id, 'code' => $code],
            ['name' => $name, 'type' => 'pos'],
        );
        if ($device->wasRecentlyCreated) {
            $device->forceFill(['status' => DeviceStatus::Pending])->save();
        }
    }

    /**
     * @param  list<array<int, mixed>>  $options
     */
    private function group(Brand $brand, string $name, int $min, int $max, array $options): string
    {
        $group = ModifierGroup::query()->where('brand_id', $brand->id)->where('name', $name)->first();
        if ($group !== null) {
            return $group->id;
        }

        return app(ModifierGroupWriter::class)->save(new ModifierGroup, [
            'brand_id' => $brand->id,
            'name' => $name,
            'min_select' => $min,
            'max_select' => $max,
            'modifiers' => array_map(fn (array $o) => [
                'name' => $o[0], 'price' => $o[1], 'is_default' => $o[2] ?? false,
            ], $options),
        ])->id;
    }

    /** @param  array<string, mixed>  $extra */
    private function item(Brand $brand, MenuCategory $category, string $sku, string $name, string $price, ?string $stationId, array $extra = []): void
    {
        if (Item::query()->where('brand_id', $brand->id)->where('sku', $sku)->exists()) {
            return;
        }

        app(ItemWriter::class)->save(null, $extra + [
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'sku' => $sku,
            'name' => $name,
            'base_price' => $price,
            'kitchen_station_id' => $stationId,
        ]);
    }

    /** @param  list<string>  $roles */
    private function staff(User $owner, string $name, string $email, string $code, array $roles, string $outletId, string $pin): void
    {
        $sudahAda = CompanyUser::query()->whereHas('user', fn ($q) => $q->where('email', $email))->first();
        if ($sudahAda !== null) {
            // Data lama dari seeder versi awal bisa tertinggal dalam keadaan "aktif tapi undangan
            // belum diterima", yang membuat penyuntingan staf di back-office selalu ditolak.
            $this->terimaUndangan($sudahAda);

            return;
        }

        // Akun dibuat lebih dulu dengan password demo; bila diserahkan ke StaffManager, ia akan
        // mengirim tautan reset password yang tidak punya rute di lingkungan console.
        app(TenantContext::class)->runAsSystem(function () use ($name, $email): void {
            User::query()->firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => DemoAccounts::PASSWORD,
            ])->forceFill(['email_verified_at' => now()])->save();
        });

        $member = app(StaffManager::class)->create($owner, [
            'name' => $name,
            'email' => $email,
            'phone' => null,
            'employee_code' => $code,
            'roles' => $roles,
            'scopes' => ['outlets' => [$outletId], 'brands' => []],
            'pin' => $pin,
        ]);

        $this->terimaUndangan($member);
    }

    /**
     * Karena akun e-mail-nya sudah dibuat lebih dulu, StaffManager memperlakukan staf ini sebagai
     * *undangan* — tidak aktif sampai pemilik akun menerimanya. Untuk data contoh undangannya
     * langsung dianggap diterima. `accepted_at` wajib ikut diisi: anggota yang aktif tetapi
     * `invited_at` terisi dan `accepted_at` kosong dianggap masih menunggu undangan, sehingga
     * menyimpan perubahan staf (termasuk ganti PIN) akan ditolak.
     */
    private function terimaUndangan(CompanyUser $member): void
    {
        if ($member->is_active && ! $member->isPendingInvitation()) {
            return;
        }

        $member->forceFill(['is_active' => true, 'invited_at' => null, 'accepted_at' => now()])->save();
    }
}
