<?php

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Sales\Domain\Models\OpenBill;
use App\Modules\Sales\Domain\Models\Order;
use Illuminate\Support\Str;
use Tests\Support\Pos;

/**
 * Parkir bill (FR-POS-12): tamu makan dulu, membayar belakangan.
 *
 * Tagihan terbuka bukan catatan keuangan — boleh diubah, dan baru menjadi transaksi resmi
 * saat dilunasi. Yang dijaga di sini: kepemilikan outlet, penutupan yang atomik bersama
 * pembayaran, dan tidak bisa dibayar dua kali.
 */
beforeEach(function () {
    $this->pos = Pos::setup();
});

/** @return array<string, mixed> */
function billPayload(Pos $pos, array $overrides = []): array
{
    return array_replace([
        'id' => (string) Str::uuid7(),
        'channel_code' => 'dine_in',
        'label' => 'Meja 7',
        'customer_name' => 'Pak Budi',
        'lines' => [
            ['id' => (string) Str::uuid7(), 'item_id' => $pos->croissant->id, 'name' => 'Croissant', 'qty' => '2.000'],
        ],
        'totals' => ['subtotal' => '50000', 'total' => '57800'],
    ], $overrides);
}

it('menyimpan, membuka kembali, dan memperbarui tagihan terbuka', function () {
    $token = $this->pos->login('cashier');
    $payload = billPayload($this->pos);

    $this->postJson('/api/v1/pos/open-bills', $payload, bearer($token))
        ->assertCreated()
        ->assertJsonPath('data.label', 'Meja 7')
        ->assertJsonPath('data.line_count', 1)
        ->assertJsonPath('data.closed_at', null);

    // Daftar tagihan terbuka di outlet ini.
    $this->getJson('/api/v1/pos/open-bills', bearer($token))
        ->assertOk()
        ->assertJsonPath('data.0.id', $payload['id'])
        ->assertJsonPath('data.0.opened_by_name', $this->pos->staff['cashier']['user']->name);

    // Tamu menambah pesanan: tagihan yang sama diperbarui, bukan dibuat baru.
    $payload['lines'][] = ['id' => (string) Str::uuid7(), 'item_id' => $this->pos->coffee->id, 'name' => 'Kopi Susu', 'qty' => '1.000'];
    $this->postJson('/api/v1/pos/open-bills', $payload, bearer($token))
        ->assertOk()
        ->assertJsonPath('data.line_count', 2);

    $this->getJson('/api/v1/pos/open-bills/'.$payload['id'], bearer($token))
        ->assertOk()
        ->assertJsonCount(2, 'data.lines');

    expect($this->pos->tenant(fn () => OpenBill::query()->count()))->toBe(1);
});

it('menutup tagihan saat dibayar dan menolak pembayaran kedua (FR-POS-12)', function () {
    $token = $this->pos->login('cashier');
    [$shiftId, $ymd] = $this->pos->openShift();
    $bill = billPayload($this->pos);
    $this->postJson('/api/v1/pos/open-bills', $bill, bearer($token))->assertCreated();

    $order = $this->pos->order($shiftId, $this->pos->receipt($ymd, 1), [
        'id' => (string) Str::uuid7(),
        'open_bill_id' => $bill['id'],
    ]);
    $this->postJson('/api/v1/pos/orders', $order, bearer($token))->assertCreated();

    $stored = $this->pos->tenant(fn () => OpenBill::query()->findOrFail($bill['id']));
    expect($stored->closed_at)->not->toBeNull()
        ->and($stored->order_id)->toBe($order['id']);

    // Tidak muncul lagi di daftar tagihan terbuka.
    $this->getJson('/api/v1/pos/open-bills', bearer($token))->assertOk()->assertJsonCount(0, 'data');

    // Tagihan yang sudah dibayar tidak boleh dibayar lagi, diubah, atau dibatalkan.
    $kedua = $this->pos->order($shiftId, $this->pos->receipt($ymd, 2), [
        'id' => (string) Str::uuid7(),
        'open_bill_id' => $bill['id'],
    ]);
    $this->postJson('/api/v1/pos/orders', $kedua, bearer($token))
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'OPEN_BILL_CLOSED');

    $this->postJson('/api/v1/pos/open-bills', $bill, bearer($token))->assertStatus(409);
    $this->deleteJson('/api/v1/pos/open-bills/'.$bill['id'], [], bearer($token))->assertStatus(409);

    // Transaksi kedua tidak ikut tersimpan.
    expect($this->pos->tenant(fn () => Order::query()->count()))->toBe(1);
});

it('membatalkan tagihan yang belum dibayar beserta jejak auditnya', function () {
    $token = $this->pos->login('cashier');
    $bill = billPayload($this->pos);
    $this->postJson('/api/v1/pos/open-bills', $bill, bearer($token))->assertCreated();

    $this->deleteJson('/api/v1/pos/open-bills/'.$bill['id'], ['reason' => 'Tamu batal pesan'], bearer($token))
        ->assertOk()
        ->assertJsonPath('data.cancelled', true);

    expect($this->pos->tenant(fn () => OpenBill::query()->count()))->toBe(0)
        ->and($this->pos->tenant(fn () => AuditLog::query()->where('action', 'pos.open_bill_cancelled')->count()))->toBe(1);
});

it('menolak tagihan milik outlet lain dan company lain (isolasi tenant)', function () {
    $token = $this->pos->login('cashier');
    $bill = billPayload($this->pos);
    $this->postJson('/api/v1/pos/open-bills', $bill, bearer($token))->assertCreated();

    // Perangkat outlet lain pada company yang sama tidak boleh melihat maupun membukanya.
    $lain = Pos::setup('Kopi Tepi Jalan Dago', 'DG2');
    $tokenLain = $lain->login('cashier');
    [$shiftLain, $ymdLain] = $lain->openShift();

    $this->getJson('/api/v1/pos/open-bills', bearer($tokenLain))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/pos/open-bills/'.$bill['id'], bearer($tokenLain))->assertNotFound();
    $this->deleteJson('/api/v1/pos/open-bills/'.$bill['id'], [], bearer($tokenLain))->assertNotFound();

    // Membayar tagihan milik company lain juga ditolak.
    $order = $lain->order($shiftLain, $lain->receipt($ymdLain, 1), [
        'id' => (string) Str::uuid7(),
        'open_bill_id' => $bill['id'],
    ]);
    $this->postJson('/api/v1/pos/orders', $order, bearer($tokenLain))->assertNotFound();

    expect($this->pos->tenant(fn () => OpenBill::query()->findOrFail($bill['id'])->closed_at))->toBeNull();
});

it('menghitung harga barang timbangan dari berat (FR-POS-05)', function () {
    $token = $this->pos->login('cashier');
    // Ikan dijual per kilogram: harga satuan x berat.
    $this->pos->tenant(fn () => Item::query()->whereKey($this->pos->croissant->id)
        ->update(['sold_by_weight' => true, 'unit' => 'kg', 'base_price' => '120000']));

    $katalog = $this->getJson('/api/v1/pos/catalog', bearer($this->pos->deviceToken))->assertOk()->json('data');
    $item = collect($katalog['items'])->firstWhere('id', $this->pos->croissant->id);
    expect($item['sold_by_weight'])->toBeTrue()->and($item['unit'])->toBe('kg');

    $quote = $this->postJson('/api/v1/pos/quotes', [
        'channel_code' => 'dine_in',
        'lines' => [['id' => 'ikan-1', 'item_id' => $this->pos->croissant->id, 'qty' => '1.350']],
    ], bearer($token))->assertOk();

    // 120.000 x 1,350 = 162.000
    $quote->assertJsonPath('data.lines.0.gross', '162000.00')
        ->assertJsonPath('data.lines.0.qty', '1.350');
});
