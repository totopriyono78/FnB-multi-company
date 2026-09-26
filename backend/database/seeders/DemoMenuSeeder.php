<?php

namespace Database\Seeders;

use App\Modules\Catalog\Application\AvailabilityService;
use App\Modules\Catalog\Application\ItemWriter;
use App\Modules\Catalog\Application\ModifierGroupWriter;
use App\Modules\Catalog\Application\PromotionWriter;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemPrice;
use App\Modules\Catalog\Domain\Models\KitchenStation;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use App\Modules\Catalog\Domain\Models\Promotion;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;

/**
 * Menu demo Gamatechno Group (harga Yogyakarta 2026): Hamzah Coffee dan Hamzah Resto. Dipanggil DemoSeeder di dalam konteks tenant.
 * Aman diulang: data yang sudah ada (berdasarkan SKU/nama) tidak dibuat ulang.
 */
class DemoMenuSeeder
{
    private const COFFEE_MODS = ['Tingkat Gula', 'Suhu', 'Tambahan Kopi'];

    public function hamzahCoffee(User $owner, Brand $brand, Outlet $kemang, Outlet $dago): void
    {
        $cat = $this->categories($brand, [['Kopi', 'amber'], ['Non-Kopi', 'green'], ['Makanan', 'orange'], ['Paket', 'blue']]);
        $mods = $this->modifierGroups($brand, [
            ['Tingkat Gula', 1, 1, [['Normal', '0', true], ['Less Sugar', '0'], ['Tanpa Gula', '0']]],
            ['Suhu', 1, 1, [['Dingin', '0', true], ['Panas', '0']]],
            ['Tambahan Kopi', 0, 3, [['Extra Shot', '6000'], ['Oat Milk', '8000'], ['Boba', '5000']]],
        ]);
        $coffeeMods = array_map(fn ($n) => $mods[$n], self::COFFEE_MODS);

        $kopiSusu = $this->item($brand, $cat['Kopi'], 'KSH-01', 'Kopi Susu Hamzah', '18000', 'BAR', [
            'short_name' => 'Kopi Susu H',
            'variants' => [['name' => 'Regular', 'price' => '18000', 'is_default' => true], ['name' => 'Large', 'price' => '24000']],
            'modifier_group_ids' => $coffeeMods,
            'description' => 'Espresso, susu segar, dan gula aren Kulon Progo.',
        ]);
        $americano = $this->item($brand, $cat['Kopi'], 'AMR-01', 'Americano', '20000', 'BAR', [
            'variants' => [['name' => 'Regular', 'price' => '20000', 'is_default' => true], ['name' => 'Large', 'price' => '25000']],
            'modifier_group_ids' => $coffeeMods,
        ]);
        $this->item($brand, $cat['Kopi'], 'LAT-01', 'Caffe Latte', '26000', 'BAR', [
            'variants' => [['name' => 'Regular', 'price' => '26000', 'is_default' => true], ['name' => 'Large', 'price' => '32000']],
            'modifier_group_ids' => $coffeeMods,
        ]);
        $this->item($brand, $cat['Kopi'], 'KOA-01', 'Es Kopi Aren', '22000', 'BAR', [
            'modifier_group_ids' => [$mods['Tingkat Gula'], $mods['Tambahan Kopi']],
            'channel_codes' => ['dine_in', 'take_away', 'gofood', 'grabfood', 'shopeefood'],
        ]);
        $this->item($brand, $cat['Kopi'], 'CAP-01', 'Cappuccino', '26000', 'BAR', [
            'variants' => [['name' => 'Regular', 'price' => '26000', 'is_default' => true], ['name' => 'Large', 'price' => '32000']],
            'modifier_group_ids' => $coffeeMods,
        ]);
        $this->item($brand, $cat['Kopi'], 'V60-01', 'Kopi Manual V60', '30000', 'BAR', [
            'description' => 'Seduh manual biji single origin Merapi atau Gayo.',
        ]);
        $this->item($brand, $cat['Non-Kopi'], 'MTL-01', 'Matcha Latte', '28000', 'BAR', [
            'variants' => [['name' => 'Regular', 'price' => '28000', 'is_default' => true], ['name' => 'Large', 'price' => '34000']],
            'modifier_group_ids' => [$mods['Tingkat Gula'], $mods['Suhu']],
        ]);
        $tehTarik = $this->item($brand, $cat['Non-Kopi'], 'TTR-01', 'Teh Tarik', '18000', 'BAR', [
            'modifier_group_ids' => [$mods['Tingkat Gula'], $mods['Suhu']],
        ]);
        $this->item($brand, $cat['Non-Kopi'], 'CKL-01', 'Cokelat Panas', '24000', 'BAR');
        $croissant = $this->item($brand, $cat['Makanan'], 'CRS-01', 'Croissant Butter', '22000', 'PASTRY');
        $pisang = $this->item($brand, $cat['Makanan'], 'PGK-01', 'Pisang Goreng Keju', '20000', 'KITCHEN');
        $this->item($brand, $cat['Makanan'], 'KTG-01', 'Kentang Goreng', '22000', 'KITCHEN');
        $this->item($brand, $cat['Makanan'], 'NGK-01', 'Nasi Goreng Kampung', '32000', 'KITCHEN', [
            'schedule' => [['days' => null, 'start' => '10:00', 'end' => '21:30']],
        ]);

        $this->item($brand, $cat['Paket'], 'PKT-SRP', 'Paket Sarapan', '35000', 'BAR', [
            'type' => Item::TYPE_BUNDLE,
            'schedule' => [['days' => null, 'start' => '07:00', 'end' => '11:00']],
            'bundle_groups' => [
                ['name' => 'Minuman', 'min_select' => 1, 'max_select' => 1, 'options' => [
                    ['item_id' => $kopiSusu->id, 'item_variant_id' => $kopiSusu->variants->firstWhere('name', 'Regular')?->id, 'is_default' => true],
                    ['item_id' => $americano->id, 'item_variant_id' => $americano->variants->firstWhere('name', 'Regular')?->id],
                    ['item_id' => $tehTarik->id],
                ]],
                ['name' => 'Makanan', 'min_select' => 1, 'max_select' => 1, 'options' => [
                    ['item_id' => $croissant->id, 'is_default' => true],
                    ['item_id' => $pisang->id, 'extra_price' => '3000'],
                ]],
            ],
        ]);

        // Harga ojek online lebih tinggi karena komisi; harga Dago sedikit lebih rendah.
        $gofood = SalesChannel::query()->where('code', 'gofood')->firstOrFail();
        $grab = SalesChannel::query()->where('code', 'grabfood')->firstOrFail();
        foreach ([$gofood, $grab] as $channel) {
            $this->price($kopiSusu, 'Regular', null, $channel, '22000');
            $this->price($kopiSusu, 'Large', null, $channel, '29000');
        }
        $this->price($kopiSusu, 'Regular', $dago, null, '17000');

        $this->promotion($owner, 'Happy Hour Kopi 20%', [
            'brand_id' => $brand->id, 'type' => 'percent', 'value' => '20', 'scope' => 'items',
            'category_ids' => [$cat['Kopi']->id], 'outlet_ids' => [$kemang->id, $dago->id],
            'days_of_week' => [1, 2, 3, 4, 5], 'time_start' => '14:00', 'time_end' => '17:00',
            'max_discount' => '10000', 'channel_codes' => ['dine_in', 'take_away'],
        ]);
        $this->promotion($owner, 'Hemat 10 Ribu', [
            'brand_id' => $brand->id, 'code' => 'HEMAT10', 'type' => 'amount', 'value' => '10000', 'scope' => 'order',
            'min_purchase' => '75000', 'auto_apply' => false, 'quota' => 200,
        ]);

        app(AvailabilityService::class)->set($croissant, $dago, null, true, $owner);
    }

    /**
     * Hamzah Resto: satu menu brand untuk dua outlet. Outlet ikan bakar menjual ikan per gram,
     * outlet umum menjual masakan rumahan; pelengkap dan minuman dijual di keduanya.
     * Menu yang bukan milik suatu outlet disembunyikan lewat ketersediaan per outlet.
     */
    public function hamzahResto(User $owner, Brand $brand, Outlet $ikanBakar, Outlet $umum): void
    {
        $bakaran = KitchenStation::query()->firstOrCreate(['code' => 'BAKARAN'], ['name' => 'Pembakaran', 'sort_order' => 10]);

        $cat = $this->categories($brand, [
            ['Ikan & Seafood', 'blue'], ['Menu Utama', 'orange'], ['Pelengkap', 'green'], ['Minuman', 'sky'],
        ]);
        $mods = $this->modifierGroups($brand, [
            // Ikan: wajib pilih cara olah dan rasa; rasa tertentu menambah harga (sekali per baris, bukan per gram).
            ['Cara Olah', 1, 1, [['Bakar', '0', true], ['Goreng', '0']]],
            ['Varian Rasa', 1, 1, [['Biasa', '0', true], ['Pedas', '0'], ['Asam Manis', '15000'], ['Bakar Madu', '20000']]],
            ['Level Pedas', 1, 1, [['Tidak Pedas', '0', true], ['Sedang', '0'], ['Pedas', '0'], ['Extra Pedas', '0']]],
            ['Tambahan', 0, 3, [['Telur Ceplok', '5000'], ['Keju', '6000'], ['Kerupuk', '3000']]],
        ]);

        // Harga per gram: Rp 55/gram = Rp 55.000/kg, angka yang diketik kasir sama dengan layar timbangan.
        $ikan = [];
        foreach ([
            ['IKN-NLA', 'Nila Merah', '55', 'Ikan air tawar, daging lembut, ukuran 300-600 gram.'],
            ['IKN-GRM', 'Gurame Segar', '95', 'Gurame kolam, ukuran 500 gram - 1,2 kg.'],
            ['IKN-CMI', 'Cumi Segar', '110', 'Cumi ukuran sedang, cocok dibakar atau digoreng tepung.'],
            ['IKN-KKP', 'Kakap Merah', '130', 'Kakap laut, ukuran 600 gram - 1,5 kg.'],
            ['IKN-BWL', 'Bawal Bintang', '145', 'Bawal laut, daging tebal, ukuran 400-900 gram.'],
            ['IKN-UDG', 'Udang Windu', '165', 'Udang segar ukuran besar.'],
        ] as [$sku, $name, $perGram, $description]) {
            $ikan[] = $this->item($brand, $cat['Ikan & Seafood'], $sku, $name, $perGram, null, [
                'description' => $description,
                'sold_by_weight' => true,
                'unit' => 'gram',
                'kitchen_station_id' => $bakaran->id,
                'modifier_group_ids' => [$mods['Cara Olah'], $mods['Varian Rasa']],
            ]);
        }

        $utama = [
            $this->item($brand, $cat['Menu Utama'], 'HRU-NGH', 'Nasi Goreng Hamzah', '28000', 'KITCHEN', [
                'description' => 'Nasi goreng kampung dengan ayam suwir, telur, dan kerupuk.',
                'modifier_group_ids' => [$mods['Level Pedas'], $mods['Tambahan']],
            ]),
            $this->item($brand, $cat['Menu Utama'], 'HRU-ABM', 'Ayam Bakar Madu', '32000', 'KITCHEN', [
                'description' => 'Ayam kampung bakar bumbu madu, dengan nasi dan lalapan.',
            ]),
            $this->item($brand, $cat['Menu Utama'], 'HRU-APS', 'Ayam Penyet Sambal Ijo', '27000', 'KITCHEN', [
                'modifier_group_ids' => [$mods['Level Pedas']],
            ]),
            $this->item($brand, $cat['Menu Utama'], 'HRU-SOT', 'Soto Ayam Lamongan', '22000', 'KITCHEN'),
            $this->item($brand, $cat['Menu Utama'], 'HRU-GDG', 'Gudeg Komplit', '30000', 'KITCHEN', [
                'description' => 'Gudeg nangka muda, krecek, telur pindang, dan ayam opor.',
            ]),
            $this->item($brand, $cat['Menu Utama'], 'HRU-MGJ', 'Mie Goreng Jawa', '25000', 'KITCHEN', [
                'modifier_group_ids' => [$mods['Level Pedas'], $mods['Tambahan']],
            ]),
            $this->item($brand, $cat['Menu Utama'], 'HRU-SBT', 'Sop Buntut', '55000', 'KITCHEN'),
            $this->item($brand, $cat['Menu Utama'], 'HRU-BBK', 'Bebek Goreng Kremes', '45000', 'KITCHEN', [
                'modifier_group_ids' => [$mods['Level Pedas']],
            ]),
        ];

        $this->item($brand, $cat['Pelengkap'], 'PLK-NSP', 'Nasi Putih', '6000', 'KITCHEN');
        $this->item($brand, $cat['Pelengkap'], 'PLK-LLP', 'Lalapan & Sambal', '10000', 'KITCHEN');
        $this->item($brand, $cat['Pelengkap'], 'PLK-KKG', 'Tumis Kangkung', '18000', 'KITCHEN');
        $this->item($brand, $cat['Pelengkap'], 'PLK-TAH', 'Tahu Tempe Goreng', '12000', 'KITCHEN');
        $this->item($brand, $cat['Pelengkap'], 'PLK-KRP', 'Kerupuk Udang', '5000', 'KITCHEN');

        $esTeh = $this->item($brand, $cat['Minuman'], 'MNM-ETM', 'Es Teh Manis', '8000', 'BAR');
        $this->item($brand, $cat['Minuman'], 'MNM-EJR', 'Es Jeruk Peras', '12000', 'BAR');
        $this->item($brand, $cat['Minuman'], 'MNM-JAP', 'Jus Alpukat', '20000', 'BAR');
        $this->item($brand, $cat['Minuman'], 'MNM-EKM', 'Es Kelapa Muda', '18000', 'BAR');
        $this->item($brand, $cat['Minuman'], 'MNM-AMN', 'Air Mineral', '6000', 'BAR');

        $availability = app(AvailabilityService::class);
        foreach ($ikan as $item) {
            $availability->set($item, $umum, false, null, $owner);
        }
        foreach ($utama as $item) {
            $availability->set($item, $ikanBakar, false, null, $owner);
        }

        // Diskon per menu (bukan per nota): promo tingkat nota yang otomatis tidak didukung data penjualan contoh.
        $this->promotion($owner, 'Makan Siang Hemat 10%', [
            'brand_id' => $brand->id, 'type' => 'percent', 'value' => '10', 'scope' => 'items',
            'category_ids' => [$cat['Menu Utama']->id], 'outlet_ids' => [$umum->id],
            'days_of_week' => [1, 2, 3, 4, 5], 'time_start' => '11:00', 'time_end' => '14:00', 'max_discount' => '10000',
        ]);
        $this->promotion($owner, 'Beli 3 Gratis 1 Es Teh', [
            'brand_id' => $brand->id, 'type' => 'buy_x_get_y', 'value' => '0', 'scope' => 'items',
            'buy_qty' => 3, 'get_qty' => 1, 'item_ids' => [$esTeh->id],
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rows
     * @return array<string, MenuCategory>
     */
    private function categories(Brand $brand, array $rows): array
    {
        $result = [];
        foreach ($rows as $i => [$name, $color]) {
            $result[$name] = MenuCategory::query()->firstOrCreate(
                ['brand_id' => $brand->id, 'name' => $name],
                ['color' => $color, 'sort_order' => $i],
            );
        }

        return $result;
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int, 3: list<array<int, mixed>>}>  $groups
     * @return array<string, string> nama => id
     */
    private function modifierGroups(Brand $brand, array $groups): array
    {
        $result = [];
        foreach ($groups as $i => [$name, $min, $max, $options]) {
            $group = ModifierGroup::query()->where('brand_id', $brand->id)->where('name', $name)->first();
            if ($group === null) {
                $group = app(ModifierGroupWriter::class)->save(new ModifierGroup, [
                    'brand_id' => $brand->id, 'name' => $name, 'min_select' => $min, 'max_select' => $max, 'sort_order' => $i,
                    'modifiers' => array_map(fn (array $o) => ['name' => $o[0], 'price' => $o[1], 'is_default' => $o[2] ?? false], $options),
                ]);
            }
            $result[$name] = $group->id;
        }

        return $result;
    }

    /** @param  array<string, mixed>  $extra */
    private function item(Brand $brand, MenuCategory $category, string $sku, string $name, string $price, ?string $station, array $extra = []): Item
    {
        $existing = Item::query()->with('variants')->where('brand_id', $brand->id)->where('sku', $sku)->first();
        if ($existing !== null) {
            return $existing;
        }

        return app(ItemWriter::class)->save(null, $extra + [
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'sku' => $sku,
            'name' => $name,
            'base_price' => $price,
            'kitchen_station_id' => $station ? KitchenStation::query()->where('code', $station)->value('id') : null,
        ]);
    }

    private function price(Item $item, ?string $variant, ?Outlet $outlet, ?SalesChannel $channel, string $price): void
    {
        ItemPrice::query()->firstOrCreate([
            'item_id' => $item->id,
            'item_variant_id' => $variant ? $item->variants->firstWhere('name', $variant)?->id : null,
            'outlet_id' => $outlet?->id,
            'sales_channel_id' => $channel?->id,
        ], ['price' => $price]);
    }

    /** @param  array<string, mixed>  $data */
    private function promotion(User $owner, string $name, array $data): void
    {
        if (Promotion::query()->where('name', $name)->exists()) {
            return;
        }

        app(PromotionWriter::class)->save($owner, new Promotion, $data + [
            'name' => $name,
            'starts_at' => now('Asia/Jakarta')->startOfMonth()->utc(),
            'ends_at' => now('Asia/Jakarta')->addMonths(3)->endOfMonth()->utc(),
            'auto_apply' => true,
        ]);
    }
}
