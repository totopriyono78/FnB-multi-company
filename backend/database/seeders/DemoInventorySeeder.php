<?php

namespace Database\Seeders;

use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemVariant;
use App\Modules\Catalog\Domain\Models\Modifier;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Application\RecipeService;
use App\Modules\Inventory\Application\StockCountService;
use App\Modules\Inventory\Application\StockDocumentService;
use App\Modules\Inventory\Application\StockLocations;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\Recipe;
use App\Modules\Inventory\Domain\Models\StockCount;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Purchasing\Application\GoodsReceiptService;
use App\Modules\Purchasing\Application\PurchaseOrderService;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Data inventory & pembelian demo untuk Kopi Tepi Jalan (Tahap 4): bahan, resep, pemasok, saldo awal,
 * PO yang sudah diterima & yang menunggu persetujuan, waste, transfer ke Dago, dan opname yang menunggu manajer.
 * Semua data dibuat lewat layanan aplikasi. Aman diulang (berhenti bila bahan sudah ada).
 */
class DemoInventorySeeder
{
    /** @var array<string, Ingredient> */
    private array $ing = [];

    /**
     * Bahan: kode => [nama, kategori, satuan dasar, minimum, satuan beli [nama => isi], harga per satuan dasar].
     */
    private const INGREDIENTS = [
        'KOPI-GAYO' => ['Biji Kopi Arabika Gayo', 'Kopi', 'g', '2000', ['kg' => '1000'], '245'],
        'SUSU-SEGAR' => ['Susu Segar Full Cream', 'Susu & Krim', 'ml', '6000', ['karton' => '12000', 'kotak' => '1000'], '19.5'],
        'OAT-MILK' => ['Oat Milk Barista', 'Susu & Krim', 'ml', '2000', ['karton' => '12000', 'kotak' => '1000'], '38'],
        'SKM' => ['Susu Kental Manis', 'Susu & Krim', 'g', '740', ['kaleng' => '370'], '32'],
        'GULA-AREN' => ['Gula Aren Cair', 'Pemanis', 'ml', '2000', ['jeriken' => '5000'], '34'],
        'MATCHA' => ['Bubuk Matcha Uji', 'Bubuk', 'g', '250', ['pak' => '500'], '420'],
        'COKLAT' => ['Bubuk Cokelat Premium', 'Bubuk', 'g', '500', ['pak' => '1000'], '120'],
        'TEH-TUBRUK' => ['Teh Tubruk Hitam', 'Bubuk', 'g', '250', ['pak' => '250'], '96'],
        'BOBA' => ['Boba Brown Sugar', 'Topping', 'g', '1000', ['pak' => '1000'], '45'],
        'CRS-BEKU' => ['Croissant Butter Beku', 'Pastry', 'pcs', '24', ['dus' => '24'], '9500'],
        'PISANG' => ['Pisang Kepok', 'Buah', 'pcs', '20', ['sisir' => '14'], '1200'],
        'TEPUNG-PG' => ['Tepung Pisang Goreng', 'Bahan Kering', 'g', '1000', ['pak' => '1000'], '28'],
        'KEJU' => ['Keju Cheddar', 'Susu & Krim', 'g', '500', ['blok' => '2000'], '118'],
        'MINYAK' => ['Minyak Goreng', 'Bahan Kering', 'ml', '2000', ['jeriken' => '5000'], '19'],
        'BERAS' => ['Beras Pandan Wangi', 'Bahan Kering', 'g', '5000', ['karung' => '5000'], '15.5'],
        'TELUR' => ['Telur Ayam', 'Protein', 'pcs', '30', ['tray' => '30'], '2100'],
        'BWG-MERAH' => ['Bawang Merah', 'Sayur & Bumbu', 'g', '500', ['kg' => '1000'], '42'],
        'BWG-PUTIH' => ['Bawang Putih', 'Sayur & Bumbu', 'g', '300', ['kg' => '1000'], '38'],
        'CABAI' => ['Cabai Merah Keriting', 'Sayur & Bumbu', 'g', '200', ['kg' => '1000'], '55'],
        'KECAP' => ['Kecap Manis', 'Sayur & Bumbu', 'ml', '500', ['botol' => '600'], '26'],
        'CUP-16' => ['Gelas Plastik 16 oz + Tutup', 'Kemasan', 'pcs', '200', ['dus' => '1000'], '650'],
        'CUP-22' => ['Gelas Plastik 22 oz + Tutup', 'Kemasan', 'pcs', '100', ['dus' => '1000'], '780'],
    ];

    public function kopiTepiJalan(User $owner, Outlet $kemang, Outlet $dago, User $manager, User $warehouse): void
    {
        if (Ingredient::query()->where('code', 'KOPI-GAYO')->exists()) {
            return;
        }
        $kemang = Outlet::query()->findOrFail($kemang->id);
        $dago = Outlet::query()->findOrFail($dago->id);
        $locations = app(StockLocations::class);
        $mainKemang = $locations->ensureDefault($kemang);
        $mainDago = $locations->ensureDefault($dago);
        $mainKemang->update(['name' => 'Gudang Kemang']);
        $mainDago->update(['name' => 'Gudang Dago']);

        foreach (self::INGREDIENTS as $code => [$name, $category, $unit, $min, $units]) {
            $ingredient = Ingredient::query()->create(['code' => $code, 'name' => $name, 'category' => $category, 'base_unit' => $unit, 'min_stock' => $min]);
            $first = true;
            foreach ($units as $unitName => $factor) {
                $ingredient->units()->create(['name' => $unitName, 'factor' => $factor, 'is_purchase_default' => $first]);
                $first = false;
            }
            $this->ing[$code] = $ingredient;
        }
        $bumbu = Ingredient::query()->create([
            'code' => 'BUMBU-NG', 'name' => 'Bumbu Nasi Goreng', 'category' => 'Setengah Jadi', 'base_unit' => 'g', 'kind' => Ingredient::SEMI,
            'notes' => 'Diulek pagi hari, tahan 2 hari di chiller.',
        ]);

        $this->recipes($owner, $bumbu);
        [$dairy, $roastery, $pastry, $market] = $this->suppliers();

        $documents = app(StockDocumentService::class);
        // Saldo awal Kemang & Dago (3 hari lalu). Matcha & boba sengaja di bawah minimum agar muncul di stok kritis.
        $opening = [];
        foreach (self::INGREDIENTS as $code => [, , , $min, , $cost]) {
            $factor = in_array($code, ['MATCHA', 'BOBA'], true) ? '0.8' : '3';
            $opening[] = ['ingredient_id' => $this->ing[$code]->id, 'qty' => (string) BigDecimal::of($min)->multipliedBy($factor)->toScale(0), 'unit_cost' => $cost];
        }
        $documents->adjust($warehouse, [
            'location_id' => $mainKemang->id, 'type' => 'adjustment', 'reason_code' => 'opening',
            'notes' => 'Saldo awal saat mulai memakai FnB Cloud', 'occurred_at' => now()->subDays(3)->toIso8601String(), 'lines' => $opening,
        ]);
        $documents->adjust($warehouse, [
            'location_id' => $mainDago->id, 'type' => 'adjustment', 'reason_code' => 'opening',
            'notes' => 'Saldo awal saat mulai memakai FnB Cloud', 'occurred_at' => now()->subDays(3)->toIso8601String(),
            'lines' => array_slice($opening, 0, 10),
        ]);

        // PO susu: diajukan manajer Kemang, disetujui pemilik, diterima gudang (harga faktur naik sedikit).
        $orders = app(PurchaseOrderService::class);
        $receipts = app(GoodsReceiptService::class);
        $po = $orders->create($manager, [
            'location_id' => $mainKemang->id, 'supplier_id' => $dairy->id, 'order_date' => now()->subDays(3)->format('Y-m-d'),
            'expected_date' => now()->subDays(2)->format('Y-m-d'), 'notes' => 'Kirim sebelum jam 8 pagi lewat pintu belakang.',
            'lines' => [
                ['ingredient_id' => $this->ing['SUSU-SEGAR']->id, 'unit_name' => 'karton', 'qty' => '4', 'unit_price' => '234000'],
                ['ingredient_id' => $this->ing['OAT-MILK']->id, 'unit_name' => 'karton', 'qty' => '1', 'unit_price' => '456000'],
            ],
        ]);
        $orders->submit($manager, $po);
        $orders->approve($owner, $po, 'Sesuai kontrak harga September');
        $po->refresh()->load('lines');
        $receipts->fromPurchaseOrder($warehouse, $po, [
            'received_at' => now()->subDays(2)->toIso8601String(),
            'supplier_invoice_no' => 'SSL/INV/2609/0412',
            'lines' => [
                ['purchase_order_line_id' => $po->lines[0]->id, 'qty' => '4', 'unit_price' => '240000'],
                ['purchase_order_line_id' => $po->lines[1]->id, 'qty' => '1'],
            ],
        ]);

        // PO roastery: diterima sebagian (sisa menunggu roasting).
        $coffee = $orders->create($warehouse, [
            'location_id' => $mainKemang->id, 'supplier_id' => $roastery->id, 'order_date' => now()->subDays(2)->format('Y-m-d'),
            'lines' => [['ingredient_id' => $this->ing['KOPI-GAYO']->id, 'unit_name' => 'kg', 'qty' => '10', 'unit_price' => '245000']],
        ]);
        $orders->submit($warehouse, $coffee);
        $orders->approve($owner, $coffee, null);
        $coffee->refresh()->load('lines');
        $receipts->fromPurchaseOrder($warehouse, $coffee, [
            'received_at' => now()->subDays(2)->toIso8601String(),
            'supplier_invoice_no' => 'RGM-0916-07',
            'lines' => [['purchase_order_line_id' => $coffee->lines[0]->id, 'qty' => '6']],
        ]);

        // PO pastry menunggu persetujuan (muncul di dashboard pemilik).
        $pastryPo = $orders->create($manager, [
            'location_id' => $mainKemang->id, 'supplier_id' => $pastry->id,
            'expected_date' => now()->addDays(2)->format('Y-m-d'),
            'lines' => [
                ['ingredient_id' => $this->ing['CRS-BEKU']->id, 'unit_name' => 'dus', 'qty' => '3', 'unit_price' => '228000'],
            ],
        ]);
        $orders->submit($manager, $pastryPo);

        // Draf PO Dago.
        $orders->create($warehouse, [
            'location_id' => $mainDago->id, 'supplier_id' => $dairy->id,
            'lines' => [['ingredient_id' => $this->ing['SUSU-SEGAR']->id, 'unit_name' => 'karton', 'qty' => '2', 'unit_price' => '234000']],
        ]);

        // Belanja pasar tanpa PO.
        $receipts->manual($manager, [
            'location_id' => $mainKemang->id, 'supplier_id' => $market->id, 'supplier_invoice_no' => 'Nota 0917',
            'received_at' => now()->subDays(2)->toIso8601String(),
            'lines' => [
                ['ingredient_id' => $this->ing['PISANG']->id, 'unit_name' => 'sisir', 'qty' => '3', 'unit_price' => '17000'],
                ['ingredient_id' => $this->ing['BWG-MERAH']->id, 'unit_name' => 'kg', 'qty' => '1', 'unit_price' => '44000'],
                ['ingredient_id' => $this->ing['CABAI']->id, 'unit_name' => 'g', 'qty' => '500', 'unit_price' => '58'],
            ],
        ]);

        // Waste kemarin.
        $documents->adjust($manager, [
            'location_id' => $mainKemang->id, 'type' => 'waste', 'reason_code' => 'expired',
            'notes' => 'Susu lewat tanggal kedaluwarsa', 'occurred_at' => now()->subDay()->toIso8601String(),
            'lines' => [['ingredient_id' => $this->ing['SUSU-SEGAR']->id, 'qty' => '1000', 'note' => '1 kotak']],
        ]);
        $documents->adjust($manager, [
            'location_id' => $mainKemang->id, 'type' => 'waste', 'reason_code' => 'damaged',
            'occurred_at' => now()->subDay()->toIso8601String(),
            'lines' => [['ingredient_id' => $this->ing['CRS-BEKU']->id, 'qty' => '2', 'note' => 'Remuk saat pengiriman']],
        ]);

        // Transfer ke Dago dalam perjalanan.
        $documents->send($warehouse, [
            'from_location_id' => $mainKemang->id, 'to_location_id' => $mainDago->id,
            'notes' => 'Tambahan stok akhir pekan Dago',
            'lines' => [
                ['ingredient_id' => $this->ing['KOPI-GAYO']->id, 'qty' => '2000'],
                ['ingredient_id' => $this->ing['GULA-AREN']->id, 'qty' => '1000'],
            ],
        ]);
    }

    /** Opname sebagian Kemang setelah penjualan demo; menunggu persetujuan manajer. */
    public function afterSales(Outlet $kemang, User $warehouse): void
    {
        $location = StockLocation::query()->where('outlet_id', $kemang->id)->where('is_default', true)->first();
        if ($location === null || StockCount::query()->where('location_id', $location->id)->exists() || $this->ing === []) {
            return;
        }
        $counts = app(StockCountService::class);
        $count = $counts->start($warehouse, [
            'location_id' => $location->id, 'scope' => 'partial', 'notes' => 'Cek bahan bar setelah tutup shift',
            'ingredient_ids' => [$this->ing['KOPI-GAYO']->id, $this->ing['SUSU-SEGAR']->id, $this->ing['GULA-AREN']->id, $this->ing['CUP-16']->id],
        ]);
        $variance = ['KOPI-GAYO' => '-35', 'SUSU-SEGAR' => '-180', 'GULA-AREN' => '0', 'CUP-16' => '-4'];
        $lines = [];
        foreach ($count->lines as $line) {
            $code = array_search($line->ingredient_id, array_map(fn (Ingredient $i) => $i->id, $this->ing), true);
            $lines[] = [
                'ingredient_id' => $line->ingredient_id,
                'counted_qty' => (string) BigDecimal::max(BigDecimal::zero(), BigDecimal::of((string) $line->system_qty)->plus($variance[$code] ?? '0'))->toScale(0, RoundingMode::DOWN),
                'note' => $code === 'CUP-16' ? '4 gelas penyok' : null,
            ];
        }
        $counts->record($warehouse, $count, $lines);
        $counts->submit($warehouse, $count);
    }

    private function recipes(User $owner, Ingredient $bumbu): void
    {
        $service = app(RecipeService::class);
        $i = fn (string $code) => $this->ing[$code]->id;
        $lines = fn (array $pairs) => array_map(fn ($code, $qty) => ['ingredient_id' => $i($code), 'qty' => $qty], array_keys($pairs), array_values($pairs));
        $item = fn (string $sku) => Item::query()->with('variants')->where('sku', $sku)->firstOrFail();
        $variant = fn (Item $it, string $name): ItemVariant => $it->variants->firstWhere('name', $name);

        $service->save($owner, Recipe::INGREDIENT, $bumbu->id, [
            'yield_qty' => '700', 'notes' => 'Satu kali ulek untuk ±20 porsi',
            'lines' => $lines(['BWG-MERAH' => '300', 'BWG-PUTIH' => '150', 'CABAI' => '100', 'KECAP' => '200']),
        ]);
        $this->ing['BUMBU-NG'] = $bumbu;

        $menu = [
            'KSTJ-01' => [['KOPI-GAYO' => '18', 'SUSU-SEGAR' => '120', 'GULA-AREN' => '20', 'CUP-16' => '1'], ['Large' => ['KOPI-GAYO' => '18', 'SUSU-SEGAR' => '180', 'GULA-AREN' => '30', 'CUP-22' => '1']]],
            'AMR-01' => [['KOPI-GAYO' => '18', 'CUP-16' => '1'], ['Large' => ['KOPI-GAYO' => '27', 'CUP-22' => '1']]],
            'LAT-01' => [['KOPI-GAYO' => '18', 'SUSU-SEGAR' => '200', 'CUP-16' => '1'], ['Large' => ['KOPI-GAYO' => '18', 'SUSU-SEGAR' => '260', 'CUP-22' => '1']]],
            'KOA-01' => [['KOPI-GAYO' => '18', 'SUSU-SEGAR' => '100', 'GULA-AREN' => '30', 'CUP-16' => '1'], []],
            'MTL-01' => [['MATCHA' => '5', 'SUSU-SEGAR' => '200', 'GULA-AREN' => '15', 'CUP-16' => '1'], ['Large' => ['MATCHA' => '7', 'SUSU-SEGAR' => '260', 'GULA-AREN' => '20', 'CUP-22' => '1']]],
            'TTR-01' => [['TEH-TUBRUK' => '5', 'SKM' => '30', 'CUP-16' => '1'], []],
            'CKL-01' => [['COKLAT' => '25', 'SUSU-SEGAR' => '200', 'CUP-16' => '1'], []],
            'CRS-01' => [['CRS-BEKU' => '1'], []],
            'PGK-01' => [['PISANG' => '2', 'TEPUNG-PG' => '40', 'MINYAK' => '30', 'KEJU' => '20'], []],
            'NGK-01' => [['BERAS' => '150', 'TELUR' => '1', 'BUMBU-NG' => '35', 'MINYAK' => '15'], []],
        ];
        foreach ($menu as $sku => [$base, $variants]) {
            $it = $item($sku);
            $service->save($owner, Recipe::ITEM, $it->id, ['lines' => $lines($base)]);
            foreach ($variants as $name => $pairs) {
                $service->save($owner, Recipe::VARIANT, $variant($it, $name)->id, ['lines' => $lines($pairs)]);
            }
        }

        $modifier = fn (string $group, string $name) => Modifier::query()
            ->whereHas('group', fn ($q) => $q->where('name', $group)->where('brand_id', $item('KSTJ-01')->brand_id))
            ->where('name', $name)->firstOrFail();
        $service->save($owner, Recipe::MODIFIER, $modifier('Tambahan Kopi', 'Extra Shot')->id, ['lines' => $lines(['KOPI-GAYO' => '9'])]);
        $service->save($owner, Recipe::MODIFIER, $modifier('Tambahan Kopi', 'Oat Milk')->id, ['notes' => 'Mengganti susu segar', 'lines' => $lines(['OAT-MILK' => '150', 'SUSU-SEGAR' => '-120'])]);
        $service->save($owner, Recipe::MODIFIER, $modifier('Tambahan Kopi', 'Boba')->id, ['lines' => $lines(['BOBA' => '40'])]);
        $service->save($owner, Recipe::MODIFIER, $modifier('Tingkat Gula', 'Less Sugar')->id, ['lines' => $lines(['GULA-AREN' => '-10'])]);
        $service->save($owner, Recipe::MODIFIER, $modifier('Tingkat Gula', 'Tanpa Gula')->id, ['lines' => $lines(['GULA-AREN' => '-20'])]);
    }

    /** @return array{0: Supplier, 1: Supplier, 2: Supplier, 3: Supplier} */
    private function suppliers(): array
    {
        $make = fn (array $attrs) => Supplier::query()->create($attrs);

        return [
            $make(['code' => 'SUP-SSL', 'name' => 'CV Sumber Susu Lembang', 'contact_name' => 'Wulan Sari', 'phone' => '0812-2045-7781',
                'email' => 'order@sumbersusu.test', 'address' => 'Jl. Raya Lembang No. 210, Kabupaten Bandung Barat', 'payment_term_days' => 14]),
            $make(['code' => 'SUP-RGM', 'name' => 'Roastery Gayo Mandiri', 'contact_name' => 'Teuku Rizal', 'phone' => '0852-6011-3490',
                'email' => 'sales@gayomandiri.test', 'address' => 'Jl. Setiabudi No. 45, Bandung', 'payment_term_days' => 30]),
            $make(['code' => 'SUP-BNU', 'name' => 'PT Boulangerie Nusantara', 'contact_name' => 'Maria Gunadi', 'phone' => '021-7888-2140',
                'address' => 'Kawasan Industri Pulogadung Blok C, Jakarta Timur', 'payment_term_days' => 30]),
            $make(['code' => 'SUP-PSR', 'name' => 'Toko Sembako Haji Ahmad', 'contact_name' => 'H. Ahmad Fauzi', 'phone' => '0813-1122-9087',
                'address' => 'Pasar Mampang Blok B No. 12, Jakarta Selatan', 'payment_term_days' => 0, 'notes' => 'Bayar tunai, buka jam 05.00']),
        ];
    }
}
