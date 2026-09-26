<?php

use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderDiscount;
use App\Modules\Tenancy\Domain\Models\Brand;
use Illuminate\Support\Str;
use Tests\Support\Menu;
use Tests\Support\Pos;

/**
 * Serah terima quote → pembayaran (FR-POS-03, BR-05, BR-18).
 *
 * Kasir menampilkan angka dari `POST /pos/quotes`, lalu mengirim angka itu juga ke
 * `POST /pos/orders`. Server menghitung ulang dan menolak bila berbeda. Karena itu semua
 * bahan perhitungan — termasuk rincian diskon promo — harus ikut dikembalikan oleh quote
 * dan dikirim balik apa adanya oleh perangkat.
 *
 * Pernah gagal di lapangan: POS mengirim `discounts: []`, sehingga setiap promo otomatis
 * (mis. happy hour) membuat pembayaran ditolak TOTAL_MISMATCH pada jam promo saja.
 */
beforeEach(function () {
    $this->pos = Pos::setup();
});

/**
 * Menyusun badan pesanan persis seperti layar kasir: baris, diskon, dan total diambil
 * mentah-mentah dari jawaban quote.
 *
 * @param  array<string, mixed>  $quote
 * @return array<string, mixed>
 */
function pesananDariQuote(Pos $pos, array $quote, string $shiftId, string $receipt): array
{
    $salin = fn (array $daftar) => array_map(fn (array $d) => [
        'type' => $d['type'], 'value' => $d['value'], 'source' => $d['source'], 'reason' => $d['reason'] ?? null,
    ], $daftar);

    $lines = array_map(fn (array $l) => [
        'id' => $l['id'],
        'item_id' => $l['item_id'],
        'variant_id' => $l['variant']['id'] ?? null,
        'name' => $l['name'],
        'qty' => $l['qty'],
        'unit_price' => $l['unit_price'],
        'modifiers' => array_map(fn (array $m) => [
            'id' => $m['id'], 'name' => $m['name'], 'price' => $m['price'], 'qty' => $m['qty'],
        ], $l['modifiers']),
        'discounts' => $salin($l['discounts']),
    ], $quote['lines']);

    $keys = ['subtotal', 'item_discount', 'order_discount', 'service_charge', 'tax', 'rounding', 'total'];

    return [
        'id' => (string) Str::uuid7(),
        'shift_id' => $shiftId,
        'cashier_id' => $pos->userId('cashier'),
        'receipt_no' => $receipt,
        'channel_code' => 'dine_in',
        'status' => 'paid',
        'created_at' => now()->toIso8601String(),
        'completed_at' => now()->toIso8601String(),
        'pricing' => $pos->pricing(),
        'lines' => $lines,
        'order_discounts' => $salin($quote['order_discounts']),
        'totals' => array_intersect_key($quote['totals'], array_flip($keys)),
        'payments' => [[
            'id' => (string) Str::uuid7(),
            'method' => 'cash',
            'amount' => $quote['totals']['total'],
            'tendered' => '200000',
            'created_at' => now()->toIso8601String(),
        ]],
    ];
}

it('membayar memakai angka quote saat promo otomatis per baris aktif (BR-18)', function () {
    $promo = Menu::promotion($this->pos->company, ['name' => 'Croissant Hemat', 'type' => 'percent', 'value' => '10'], [$this->pos->croissant->id]);
    $token = $this->pos->login('cashier');
    [$shiftId, $ymd] = $this->pos->openShift();

    $quote = $this->postJson('/api/v1/pos/quotes', [
        'channel_code' => 'dine_in',
        'lines' => [['item_id' => $this->pos->croissant->id, 'qty' => 2]],
    ], bearer($token))->assertOk()->json('data');

    // Quote memang menerapkan promo; tanpa ini uji di bawah tidak membuktikan apa pun.
    expect($quote['totals']['item_discount'])->toBe('5000.00')
        ->and($quote['lines'][0]['discounts'])->toHaveCount(1);

    $order = pesananDariQuote($this->pos, $quote, $shiftId, $this->pos->receipt($ymd, 1));
    $this->postJson('/api/v1/pos/orders', $order, bearer($token))->assertCreated();

    $tersimpan = $this->pos->tenant(fn () => Order::query()->findOrFail($order['id']));
    expect($tersimpan->total)->toBe($quote['totals']['total'])
        ->and($tersimpan->item_discount)->toBe('5000.00')
        // Diskonnya tercatat sebagai promo, bukan diskon manual kasir.
        ->and($this->pos->tenant(fn () => OrderDiscount::query()->where('order_id', $order['id'])->sole()->promotion_id))
        ->toBe($promo->id);
});

it('membayar memakai angka quote saat promo tingkat transaksi aktif (BR-18)', function () {
    Menu::promotion($this->pos->company, [
        'name' => 'Hemat Lima Ribu', 'type' => 'amount', 'value' => '5000', 'scope' => 'order', 'min_purchase' => '30000',
    ]);
    $token = $this->pos->login('cashier');
    [$shiftId, $ymd] = $this->pos->openShift();

    $quote = $this->postJson('/api/v1/pos/quotes', [
        'channel_code' => 'dine_in',
        'lines' => [['item_id' => $this->pos->croissant->id, 'qty' => 2]],
    ], bearer($token))->assertOk()->json('data');

    expect($quote['order_discounts'])->toHaveCount(1)
        ->and($quote['totals']['order_discount'])->toBe('5000.00');

    $order = pesananDariQuote($this->pos, $quote, $shiftId, $this->pos->receipt($ymd, 1));
    $this->postJson('/api/v1/pos/orders', $order, bearer($token))->assertCreated();

    expect($this->pos->tenant(fn () => Order::query()->findOrFail($order['id'])->order_discount))->toBe('5000.00');
});

it('membayar memakai angka quote untuk ikan timbangan dengan tambahan (FR-POS-11)', function () {
    $brand = $this->pos->tenant(fn () => Brand::query()->findOrFail($this->pos->croissant->brand_id));
    $rasa = Menu::modifierGroup($this->pos->company, $brand, [['name' => 'Bakar Madu', 'price' => '20000']], 0, 1, 'Varian Rasa');
    $ikan = Menu::item($this->pos->company, $brand, [
        'name' => 'Gurame Segar', 'sku' => 'IKN-GRM', 'base_price' => '95',
        'sold_by_weight' => true, 'unit' => 'gram',
        'modifier_group_ids' => [$rasa->id],
    ]);
    $token = $this->pos->login('cashier');
    [$shiftId, $ymd] = $this->pos->openShift();

    $quote = $this->postJson('/api/v1/pos/quotes', [
        'channel_code' => 'dine_in',
        'lines' => [['item_id' => $ikan->id, 'qty' => '850', 'modifiers' => [['id' => $rasa->modifiers->first()->id]]]],
    ], bearer($token))->assertOk()->json('data');

    // 95/gram x 850 gram = 80.750, ditambah bumbu 20.000 SEKALI — bukan 20.000 dikali 850 gram.
    expect($quote['totals']['subtotal'])->toBe('100750.00');

    $order = pesananDariQuote($this->pos, $quote, $shiftId, $this->pos->receipt($ymd, 1));
    $this->postJson('/api/v1/pos/orders', $order, bearer($token))->assertCreated();

    expect($this->pos->tenant(fn () => Order::query()->findOrFail($order['id'])->subtotal))->toBe('100750.00');
});
