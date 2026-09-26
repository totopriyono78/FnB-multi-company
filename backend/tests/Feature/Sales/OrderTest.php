<?php

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\Promotion;
use App\Modules\Sales\Application\Authorizations;
use App\Modules\Sales\Application\SalesException;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderDiscount;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Factory;
use Tests\Support\Menu;
use Tests\Support\Pos;

beforeEach(function () {
    $this->pos = Pos::setup();
    [$this->shiftId, $this->ymd] = $this->pos->openShift();
});

/** Diskon transaksi 10% manual. Subtotal 68.000 − 6.800 = 61.200; SC 3.060; PB1 6.426; 70.686 → 70.700 (+14). */
function tenPercentOrder(Pos $pos, string $shiftId, string $receipt, array $extra = []): array
{
    return $pos->order($shiftId, $receipt, array_replace([
        'order_discounts' => [['type' => 'percent', 'value' => '10', 'source' => 'manual', 'reason' => 'Pelanggan tetap']],
        'totals' => ['subtotal' => '68000', 'item_discount' => '0', 'order_discount' => '6800', 'service_charge' => '3060', 'tax' => '6426', 'rounding' => '14', 'total' => '70700'],
        'payments' => [['id' => (string) Str::uuid7(), 'method' => 'cash', 'amount' => '70700', 'created_at' => now()->subMinutes(9)->toIso8601String()]],
    ], $extra));
}

it('menyimpan transaksi tunai lengkap dengan kembalian (FR-POS-10, FR-PAY-03)', function () {
    $token = $this->pos->login('cashier');
    $payload = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1), ['id' => (string) Str::uuid7(), 'queue_no' => 12, 'table_label' => 'A3']);

    $response = $this->postJson('/api/v1/pos/orders', $payload, bearer($token));

    $response->assertCreated()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.total', '78500.00')
        ->assertJsonPath('data.rounding', '-40.00')
        ->assertJsonPath('data.paid_total', '78500.00')
        ->assertJsonPath('data.change_amount', '21500.00')
        ->assertJsonPath('data.flags', [])
        // Nomor meja ikut tersimpan dan dikembalikan: dipakai tiket dapur dan struk.
        ->assertJsonPath('data.table_label', 'A3')
        ->assertJsonPath('data.queue_no', 12)
        ->assertJsonPath('data.items.0.gross', '50000.00')
        ->assertJsonPath('data.items.1.variant_name', 'Regular')
        ->assertJsonPath('data.payments.0.tendered', '100000.00');

    // Kiriman ulang aman (idempoten)
    $this->postJson('/api/v1/pos/orders', $payload, bearer($token))->assertOk()->assertJsonPath('meta.sync_status', 'duplicate');
    expect($this->pos->tenant(fn () => Order::query()->count()))->toBe(1);
});

it('menolak total yang berbeda sesen pun dari perhitungan server (BR-05)', function () {
    $payload = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1));
    $payload['totals']['tax'] = '7139';

    $result = $this->pos->push('order', $payload);

    expect($result['status'])->toBe('rejected')
        ->and($result['error']['code'])->toBe('TOTAL_MISMATCH')
        ->and($result['error']['retryable'])->toBeFalse()
        ->and($result['error']['details']['expected']['tax'])->toBe('7140.00');
    expect($this->pos->tenant(fn () => AuditLog::query()->where('action', 'sync.entity_rejected')->count()))->toBe(1);
});

it('menolak nomor struk yang salah format atau dipakai dua kali (FR-POS-24)', function () {
    $bad = $this->pos->push('order', $this->pos->order($this->shiftId, 'KMG-XX-000000-0001'));
    expect($bad['error']['code'])->toBe('INVALID_RECEIPT_NO');

    $receipt = $this->pos->receipt($this->ymd, 7);
    expect($this->pos->push('order', $this->pos->order($this->shiftId, $receipt))['status'])->toBe('accepted');
    expect($this->pos->push('order', $this->pos->order($this->shiftId, $receipt))['error']['code'])->toBe('DUPLICATE_RECEIPT_NO');
});

it('menerima harga yang berubah setelah perangkat offline tetapi menandainya', function () {
    // Croissant 24.000 × 2 + Kopi 18.000 = 66.000; SC 3.300; PB1 6.930; 76.230 → 76.200 (−30)
    $payload = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1), [
        'totals' => ['subtotal' => '66000', 'item_discount' => '0', 'order_discount' => '0', 'service_charge' => '3300', 'tax' => '6930', 'rounding' => '-30', 'total' => '76200'],
        'payments' => [['id' => (string) Str::uuid7(), 'method' => 'cash', 'amount' => '76200', 'created_at' => now()->toIso8601String()]],
    ]);
    $payload['lines'][0]['unit_price'] = '24000';

    // Harga katalog tidak berubah sejak transaksi → harga lebih rendah dianggap ubah harga manual.
    expect($this->pos->push('order', $payload)['error']['code'])->toBe('AUTHORIZATION_REQUIRED');

    // Harga naik dari 24.000 ke 25.000 setelah perangkat terakhir menarik data (perangkat masih memakai harga lama).
    $this->travel(2)->seconds();
    $this->pos->tenant(function () {
        $item = Item::query()->findOrFail($this->pos->croissant->id);
        $item->update(['base_price' => '24000']);
        $item->update(['base_price' => '25000']);
    });
    // Hanya riwayat harga yang menjadi dasar (waktu ubah menu lain dimundurkan).
    $this->pos->backdate();
    $result = $this->pos->push('order', $payload);

    expect($result['status'])->toBe('accepted')->and($result['flags'])->toBe(['price_mismatch']);
    $item = $this->pos->tenant(fn () => DB::table('order_items')->where('order_id', $result['order_id'])->where('line_no', 1)->first());
    expect((string) $item->unit_price)->toBe('24000.00')->and((string) $item->catalog_price)->toBe('25000.00');
    expect($this->pos->tenant(fn () => AuditLog::query()->where('action', 'order.flagged')->count()))->toBe(1);
});

it('ubah harga manual wajib otorisasi supervisor sekali pakai (FR-POS-14)', function () {
    $make = function (int $seq, array $auth = []) {
        $payload = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, $seq), [
            'totals' => ['subtotal' => '66000', 'item_discount' => '0', 'order_discount' => '0', 'service_charge' => '3300', 'tax' => '6930', 'rounding' => '-30', 'total' => '76200'],
            'payments' => [['id' => (string) Str::uuid7(), 'method' => 'cash', 'amount' => '76200', 'created_at' => now()->toIso8601String()]],
        ] + $auth);
        $payload['lines'][0]['unit_price'] = '24000';
        $payload['lines'][0]['price_override'] = true;

        return $payload;
    };

    $first = (string) Str::uuid7();
    expect($this->pos->push('order', $make(1), $first)['error']['code'])->toBe('AUTHORIZATION_REQUIRED');

    // Otorisasi untuk aksi lain, atau tanpa menyebut transaksinya, tidak berlaku.
    $voidAuth = $this->pos->authorize('void', 'manager', ['reference_id' => $first]);
    expect($this->pos->push('order', $make(1, ['authorizations' => ['price_override' => ['mode' => 'online', 'authorization_id' => $voidAuth]]]), $first)['error']['code'])
        ->toBe('AUTHORIZATION_INVALID');
    $noReference = $this->pos->authorize('price_override');
    expect($this->pos->push('order', $make(1, ['authorizations' => ['price_override' => ['mode' => 'online', 'authorization_id' => $noReference]]]), $first)['error']['code'])
        ->toBe('AUTHORIZATION_INVALID');

    $auth = $this->pos->authorize('price_override', 'manager', ['reference_id' => $first]);
    $accepted = $this->pos->push('order', $make(1, ['authorizations' => ['price_override' => ['mode' => 'online', 'authorization_id' => $auth]]]), $first);
    expect($accepted['status'])->toBe('accepted')->and($accepted['flags'])->toBe(['price_override']);

    // Otorisasi yang sama tidak bisa dipakai untuk transaksi lain.
    expect($this->pos->push('order', $make(2, ['authorizations' => ['price_override' => ['mode' => 'online', 'authorization_id' => $auth]]]))['error']['code'])
        ->toBe('AUTHORIZATION_INVALID');

    $log = $this->pos->tenant(fn () => AuditLog::query()->where('action', 'order.price_override')->first());
    expect($log->authorized_by)->toBe($this->pos->userId('manager'));
});

describe('diskon manual & batas role (BR-14)', function () {
    it('kasir tanpa izin diskon memerlukan otorisasi supervisor', function () {
        $payload = tenPercentOrder($this->pos, $this->shiftId, $this->pos->receipt($this->ymd, 1));
        expect($this->pos->push('order', $payload)['error']['code'])->toBe('AUTHORIZATION_REQUIRED');

        $id = (string) Str::uuid7();
        $auth = $this->pos->authorize('discount', 'manager', ['reference_id' => $id, 'discount_percent' => 10]);
        $payload['authorizations'] = ['discount' => ['mode' => 'online', 'authorization_id' => $auth]];
        $result = $this->pos->push('order', $payload, $id);

        expect($result['status'])->toBe('accepted')->and($result['flags'])->toBe([]);
        $discount = $this->pos->tenant(fn () => OrderDiscount::query()->where('order_id', $result['order_id'])->sole());
        expect($discount->source)->toBe('manual')
            ->and($discount->amount)->toBe('6800.00')
            ->and($discount->authorized_by)->toBe($this->pos->userId('manager'))
            ->and($discount->reason)->toBe('Pelanggan tetap');
    });

    it('manajer yang login sendiri boleh memberi diskon sampai batas role-nya', function () {
        $payload = tenPercentOrder($this->pos, $this->shiftId, $this->pos->receipt($this->ymd, 1), ['cashier_id' => $this->pos->userId('manager')]);

        // ID manajer yang dikirim dengan token perangkat/kasir lain tidak memberi hak manajer.
        expect($this->pos->push('order', $payload)['error']['code'])->toBe('AUTHORIZATION_REQUIRED');
        expect($this->pos->pushAs('cashier', 'order', $payload)['error']['code'])->toBe('AUTHORIZATION_REQUIRED');

        expect($this->pos->pushAs('manager', 'order', $payload)['status'])->toBe('accepted');
    });

    it('otorisasi diskon terikat pada transaksi dan persentase yang disetujui', function () {
        $payload = tenPercentOrder($this->pos, $this->shiftId, $this->pos->receipt($this->ymd, 1));

        $otherOrder = $this->pos->authorize('discount', 'manager', ['reference_id' => (string) Str::uuid7(), 'discount_percent' => 10]);
        $payload['authorizations'] = ['discount' => ['mode' => 'online', 'authorization_id' => $otherOrder]];
        expect($this->pos->push('order', $payload)['error']['code'])->toBe('AUTHORIZATION_INVALID');

        $id = (string) Str::uuid7();
        $tooSmall = $this->pos->authorize('discount', 'manager', ['reference_id' => $id, 'discount_percent' => 5]);
        $payload['authorizations'] = ['discount' => ['mode' => 'online', 'authorization_id' => $tooSmall]];
        expect($this->pos->push('order', $payload, $id)['error']['code'])->toBe('AUTHORIZATION_INVALID');

        $ok = $this->pos->authorize('discount', 'manager', ['reference_id' => $id, 'discount_percent' => 10]);
        $payload['authorizations'] = ['discount' => ['mode' => 'online', 'authorization_id' => $ok]];
        $result = $this->pos->push('order', $payload, $id);
        expect($result['error'] ?? null)->toBeNull();
        expect($result['status'])->toBe('accepted');
    });

    it('otorisasi yang sudah lebih dari 24 jam tidak berlaku walau jam perangkat dimundurkan', function () {
        $this->travelTo(now()->subHours(25));
        $auth = $this->pos->authorize('discount', 'manager');
        $grantedAt = now()->toImmutable();
        $this->travelBack();

        $verify = fn () => $this->pos->tenant(fn () => app(Authorizations::class)->verify(
            ['mode' => 'online', 'authorization_id' => $auth], 'discount', $this->pos->device->load('outlet'), $grantedAt->addMinute(), 'authorization', (string) Str::uuid7(),
        ));

        expect($verify)->toThrow(SalesException::class, 'kedaluwarsa');
    });
});

describe('promo (FR-MENU-10, ADR 0004 butir 5)', function () {
    beforeEach(function () {
        $this->promo = Menu::promotion($this->pos->company, ['name' => 'Croissant Hemat', 'type' => 'percent', 'value' => '10', 'quota' => 1], [$this->pos->croissant->id]);
    });

    /** Promo 10% Croissant: diskon 5.000; 63.000; SC 3.150; PB1 6.615; 72.765 → 72.800 (+35). */
    function promoOrder(Pos $pos, string $shiftId, string $receipt, string $promoId): array
    {
        $payload = $pos->order($shiftId, $receipt, [
            'totals' => ['subtotal' => '68000', 'item_discount' => '5000', 'order_discount' => '0', 'service_charge' => '3150', 'tax' => '6615', 'rounding' => '35', 'total' => '72800'],
            'payments' => [['id' => (string) Str::uuid7(), 'method' => 'cash', 'amount' => '72800', 'created_at' => now()->toIso8601String()]],
        ]);
        $payload['lines'][0]['discounts'] = [['type' => 'amount', 'value' => '5000', 'source' => 'promo:'.$promoId]];

        return $payload;
    }

    it('menerima promo yang sesuai hasil mesin promo dan mengurangi kuota', function () {
        $result = $this->pos->push('order', promoOrder($this->pos, $this->shiftId, $this->pos->receipt($this->ymd, 1), $this->promo->id));

        expect($result['status'])->toBe('accepted')->and($result['flags'])->toBe([]);
        expect($this->pos->tenant(fn () => Promotion::query()->find($this->promo->id)->used_count))->toBe(1);
        $row = $this->pos->tenant(fn () => OrderDiscount::query()->where('order_id', $result['order_id'])->sole());
        expect($row->source)->toBe('promo')->and($row->promotion_id)->toBe($this->promo->id)->and($row->amount)->toBe('5000.00');
    });

    it('tetap menerima penjualan offline saat kuota ternyata habis, dengan tanda', function () {
        $this->pos->push('order', promoOrder($this->pos, $this->shiftId, $this->pos->receipt($this->ymd, 1), $this->promo->id));
        $second = $this->pos->push('order', promoOrder($this->pos, $this->shiftId, $this->pos->receipt($this->ymd, 2), $this->promo->id));

        expect($second['status'])->toBe('accepted');
        $order = $this->pos->tenant(fn () => Order::query()->find($second['order_id']));
        expect($order->flags)->toContain('promo_quota_exceeded');
    });

    it('klaim promo yang tidak dihasilkan mesin promo dihitung sebagai diskon manual', function () {
        $fake = Menu::promotion($this->pos->company, ['name' => 'Tidak Aktif', 'is_active' => false], [$this->pos->croissant->id]);
        $this->pos->backdate();

        // Kasir (batas 0%) tidak bisa menyamarkan diskon sebagai promo — termasuk ID promo acak.
        expect($this->pos->push('order', promoOrder($this->pos, $this->shiftId, $this->pos->receipt($this->ymd, 1), $fake->id))['error']['code'])
            ->toBe('AUTHORIZATION_REQUIRED');
        expect($this->pos->push('order', promoOrder($this->pos, $this->shiftId, $this->pos->receipt($this->ymd, 1), (string) Str::uuid7()))['error']['code'])
            ->toBe('AUTHORIZATION_REQUIRED');

        // Manajer (batas 50%) boleh, tetap ditandai; ID promo tidak disimpan sebagai rujukan promo sah.
        $result = $this->pos->pushAs('manager', 'order', ['cashier_id' => $this->pos->userId('manager')] + promoOrder($this->pos, $this->shiftId, $this->pos->receipt($this->ymd, 1), (string) Str::uuid7()));
        expect($result['error'] ?? null)->toBeNull();
        expect($result['status'])->toBe('accepted')->and($result['flags'])->toBe(['promo_mismatch']);
        expect($this->pos->tenant(fn () => OrderDiscount::query()->where('order_id', $result['order_id'])->sole()->promotion_id))->toBeNull();
    });

    it('promo yang diubah setelah perangkat menarik data tetap diterima dengan tanda', function () {
        $this->travel(2)->seconds();
        $this->pos->tenant(fn () => Promotion::query()->findOrFail($this->promo->id)->update(['is_active' => false]));

        $result = $this->pos->push('order', promoOrder($this->pos, $this->shiftId, $this->pos->receipt($this->ymd, 1), $this->promo->id));

        expect($result['status'])->toBe('accepted')->and($result['flags'])->toBe(['promo_mismatch']);
    });

    it('menandai transaksi yang tidak memakai promo otomatis yang berlaku', function () {
        $result = $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));

        expect($result['flags'])->toBe(['promo_mismatch']);
    });
});

describe('pembayaran (FR-PAY-03, FR-PAY-06)', function () {
    it('menerima split payment tunai + debit dengan kembalian hanya dari tunai', function () {
        $payload = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1), ['payments' => [
            ['id' => (string) Str::uuid7(), 'method' => 'debit', 'amount' => '50000', 'reference' => 'EDC-771', 'created_at' => now()->toIso8601String()],
            ['id' => (string) Str::uuid7(), 'method' => 'cash', 'amount' => '28500', 'tendered' => '30000', 'created_at' => now()->toIso8601String()],
        ]]);
        $result = $this->pos->push('order', $payload);
        expect($result['status'])->toBe('accepted');

        $order = $this->pos->tenant(fn () => Order::query()->with('payments')->find($result['order_id']));
        expect($order->change_amount)->toBe('1500.00')->and($order->payments)->toHaveCount(2);
    });

    it('menolak pembayaran yang tidak sama dengan total atau kembalian non-tunai', function () {
        $short = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1));
        $short['payments'][0]['amount'] = '78000';
        expect($this->pos->push('order', $short)['error']['code'])->toBe('PAYMENT_TOTAL_MISMATCH');

        $change = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1));
        $change['payments'][0] = ['id' => (string) Str::uuid7(), 'method' => 'debit', 'amount' => '78500', 'tendered' => '80000', 'created_at' => now()->toIso8601String()];
        expect($this->pos->push('order', $change)['error']['code'])->toBe('CHANGE_NOT_ALLOWED');

        $low = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1));
        $low['payments'][0]['tendered'] = '50000';
        expect($this->pos->push('order', $low)['error']['code'])->toBe('TENDERED_TOO_LOW');

        $unknown = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1));
        $unknown['payments'][0]['method'] = 'member_balance';
        expect($this->pos->push('order', $unknown)['error']['code'])->toBe('PAYMENT_METHOD_UNKNOWN');

        $qris = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1));
        $qris['payments'][0] = ['id' => (string) Str::uuid7(), 'method' => 'qris', 'amount' => '78500', 'created_at' => now()->toIso8601String()];
        expect($this->pos->push('order', $qris)['error']['code'])->toBe('PAYMENT_INTENT_INVALID');
    });

    it('menandai metode yang sudah dinonaktifkan outlet', function () {
        $this->pos->tenant(fn () => DB::table('outlet_payment_methods')->where('outlet_id', $this->pos->outlet->id)->where('method', 'transfer')->update(['is_active' => false]));
        $payload = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1));
        $payload['payments'][0] = ['id' => (string) Str::uuid7(), 'method' => 'transfer', 'amount' => '78500', 'created_at' => now()->toIso8601String()];

        expect($this->pos->push('order', $payload)['flags'])->toBe(['payment_method_inactive']);
    });
});

it('menolak pengaturan pajak dari perangkat yang tidak sesuai outlet', function () {
    // PB1 0%: 68.000 + SC 3.400 = 71.400 (tanpa pembulatan)
    $payload = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1), [
        'pricing' => ['tax_rate' => '0'] + $this->pos->pricing(),
        'totals' => ['subtotal' => '68000', 'item_discount' => '0', 'order_discount' => '0', 'service_charge' => '3400', 'tax' => '0', 'rounding' => '0', 'total' => '71400'],
        'payments' => [['id' => (string) Str::uuid7(), 'method' => 'cash', 'amount' => '71400', 'created_at' => now()->toIso8601String()]],
    ]);

    expect($this->pos->push('order', $payload)['error']['code'])->toBe('CONFIG_MISMATCH');
});

it('menandai konfigurasi pajak yang berubah setelah perangkat menarik data', function () {
    $this->travel(2)->seconds();
    $this->pos->tenant(fn () => Outlet::query()->findOrFail($this->pos->outlet->id)->update(['tax_rate' => '11']));

    $result = $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));

    expect($result['status'])->toBe('accepted')->and($result['flags'])->toBe(['config_mismatch']);
    $order = $this->pos->tenant(fn () => Order::query()->find($result['order_id']));
    expect($order->pricing['tax_rate'])->toBe('10')->and($order->tax)->toBe('7140.00');
});

it('menyimpan pembatalan sebelum bayar untuk laporan anti-fraud (FR-POS-16)', function () {
    $base = ['status' => 'voided', 'payments' => [], 'void' => ['reason' => 'Pelanggan batal', 'voided_by' => $this->pos->userId('cashier')]];
    $payload = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1), $base);

    expect($this->pos->push('order', $payload)['error']['code'])->toBe('AUTHORIZATION_REQUIRED');

    $id = (string) Str::uuid7();
    $payload['void']['authorization'] = ['mode' => 'online', 'authorization_id' => $this->pos->authorize('void', 'manager', ['reference_id' => $id])];
    $result = $this->pos->push('order', $payload, $id);
    expect($result['status'])->toBe('accepted');

    $order = $this->pos->tenant(fn () => Order::query()->with('items')->find($result['order_id']));
    expect($order->status)->toBe('voided')
        ->and($order->paid_total)->toBe('0.00')
        ->and($order->void_authorized_by)->toBe($this->pos->userId('manager'))
        ->and($order->items->pluck('status')->unique()->all())->toBe(['voided']);

    $withPayment = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 2), ['status' => 'voided', 'void' => ['reason' => 'x', 'voided_by' => $this->pos->userId('manager')]]);
    expect($this->pos->push('order', $withPayment)['error']['code'])->toBe('VOID_WITH_PAYMENT');
});

it('menolak kasir tanpa izin transaksi dan menu dari brand lain', function () {
    $kitchen = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1), ['cashier_id' => $this->pos->userId('kitchen')]);
    expect($this->pos->push('order', $kitchen)['error']['code'])->toBe('STAFF_NOT_ALLOWED');

    $otherBrand = Factory::brand($this->pos->company, ['code' => 'LAIN']);
    $foreignItem = Menu::item($this->pos->company, $otherBrand, ['name' => 'Roti', 'sku' => 'RT', 'base_price' => '25000']);
    $payload = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1));
    $payload['lines'][0]['item_id'] = $foreignItem->id;
    expect($this->pos->push('order', $payload)['error']['code'])->toBe('ITEM_UNKNOWN');
});

it('menerima penjualan menu yang sudah dihapus setelah transaksi offline', function () {
    $this->pos->tenant(fn () => Item::query()->findOrFail($this->pos->croissant->id)->delete());

    $result = $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));

    expect($result['status'])->toBe('accepted');
});

it('transaksi bersifat append-only di database (BR-12)', function () {
    $result = $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));
    $id = $result['order_id'];

    expect(fn () => $this->pos->tenant(fn () => DB::table('orders')->where('id', $id)->update(['total' => '1000'])))
        ->toThrow(QueryException::class, 'tidak dapat diubah');
    expect(fn () => $this->pos->tenant(fn () => DB::table('orders')->where('id', $id)->delete()))
        ->toThrow(QueryException::class);
    expect(fn () => $this->pos->tenant(fn () => DB::table('payments')->where('order_id', $id)->update(['amount' => '1'])))
        ->toThrow(QueryException::class);
    expect(fn () => $this->pos->tenant(fn () => DB::table('order_items')->where('order_id', $id)->update(['unit_price' => '1'])))
        ->toThrow(QueryException::class);
});
