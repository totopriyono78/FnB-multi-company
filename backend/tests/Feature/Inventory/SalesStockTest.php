<?php

use App\Modules\Inventory\Application\RecipeExplorer;
use App\Modules\Inventory\Domain\Events\StockBelowMinimum;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\Recipe;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Sales\Domain\Events\OrderCompleted;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Support\Collection;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\Factory;
use Tests\Support\Menu;
use Tests\Support\Pos;
use Tests\Support\Stock;

beforeEach(function () {
    $this->pos = Pos::setup();
    $company = $this->pos->company;
    $this->location = Stock::location($company, $this->pos->outlet);
    $this->beans = Stock::ingredient($company, 'Biji Kopi Arabika Gayo', 'g');
    $this->milk = Stock::ingredient($company, 'Susu Segar', 'ml');
    $this->dough = Stock::ingredient($company, 'Croissant Beku', 'pcs');
    Stock::recipe($company, Recipe::ITEM, $this->pos->coffee->id, [[$this->beans, '18'], [$this->milk, '150']]);
    $large = $this->pos->coffee->variants->firstWhere('name', 'Large');
    Stock::recipe($company, Recipe::VARIANT, $large->id, [[$this->beans, '18'], [$this->milk, '200']]);
    Stock::recipe($company, Recipe::ITEM, $this->pos->croissant->id, [[$this->dough, '1']]);
    Stock::receive($company, $this->location, $this->beans, '1000', '250');
    Stock::receive($company, $this->location, $this->milk, '5000', '18');
    Stock::receive($company, $this->location, $this->dough, '20', '9000');
    [$this->shiftId, $this->ymd] = $this->pos->openShift();
});

function useTrigger(Pos $pos, string $trigger, bool $allowNegative = true): void
{
    $pos->tenant(fn () => Outlet::query()->whereKey($pos->outlet->id)->update(['stock_deduction_trigger' => $trigger, 'allow_negative_stock' => $allowNegative]));
}

function movements(Pos $pos, ?string $type = null): Collection
{
    return $pos->tenant(fn () => StockMovement::query()->when($type, fn ($q) => $q->where('type', $type))->orderBy('created_at')->orderBy('ingredient_id')->get());
}

/** @param array<string, mixed> $order */
function ticketFor(array $order, string $orderId, array $lines = []): array
{
    $pick = $lines === [] ? $order['lines'] : array_values(array_filter($order['lines'], fn ($l) => in_array($l['id'], $lines, true)));

    return [
        'order_id' => $orderId,
        'sent_at' => now()->subMinutes(6)->toIso8601String(),
        'lines' => array_map(fn ($l) => ['id' => $l['id'], 'item_id' => $l['item_id'], 'variant_id' => $l['variant_id'] ?? null, 'qty' => $l['qty']], $pick),
    ];
}

describe('potong stok saat bayar (on_payment)', function () {
    beforeEach(fn () => useTrigger($this->pos, 'on_payment'));

    it('memotong bahan sesuai resep dengan HPP rata-rata (FR-INV-04)', function () {
        $result = $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));
        expect($result['status'])->toBe('accepted');

        expect(Stock::balance($this->pos->company, $this->location, $this->beans)['qty'])->toBe('982.0000')
            ->and(Stock::balance($this->pos->company, $this->location, $this->milk)['qty'])->toBe('4850.0000')
            ->and(Stock::balance($this->pos->company, $this->location, $this->dough)['qty'])->toBe('18.0000');

        $sales = movements($this->pos, 'sale');
        expect($sales)->toHaveCount(3)
            ->and($sales->sum(fn ($m) => (float) $m->value))->toBe(-25200.0)
            ->and($sales->first()->reference_no)->toBe($this->pos->receipt($this->ymd, 1))
            ->and($sales->pluck('created_by')->unique()->all())->toBe([$this->pos->userId('cashier')]);

        $posting = $this->pos->tenant(fn () => DB::table('stock_event_postings')->where('key', 'completed:'.$result['order_id'])->first());
        expect($posting->status)->toBe('posted');
    });

    it('memposting stok setelah respons terkirim tanpa queue worker (SRS §7.3)', function () {
        config(['fnb.inventory.defer_in_console' => true]);
        $order = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1));
        $entityId = (string) Str::uuid7();

        // Lewat HTTP: posting ditunda lalu dijalankan saat permintaan selesai.
        $result = $this->pos->push('order', $order, $entityId);
        expect($result['status'])->toBe('accepted')
            ->and(app(DeferredCallbackCollection::class)->count())->toBe(0)
            ->and(movements($this->pos, 'sale'))->toHaveCount(3);

        // Di luar HTTP: event yang sama menunggu callback deferred, dan pengulangan tidak memotong dua kali.
        event(new OrderCompleted($this->pos->company->id, $result['order_id'], now()->toDateString(), 'paid'));
        event(new OrderCompleted($this->pos->company->id, $result['order_id'], now()->toDateString(), 'paid'));
        expect(app(DeferredCallbackCollection::class)->count())->toBe(1);
        app(DeferredCallbackCollection::class)->invoke();

        expect($this->pos->push('order', $order, $entityId)['status'])->toBe('duplicate')
            ->and(movements($this->pos, 'sale'))->toHaveCount(3)
            ->and(Stock::balance($this->pos->company, $this->location, $this->beans)['qty'])->toBe('982.0000');
    });

    it('memakai resep varian bila ada dan menambah resep modifier (boleh minus)', function () {
        $company = $this->pos->company;
        $sugar = Stock::ingredient($company, 'Gula Aren Cair', 'ml');
        Stock::receive($company, $this->location, $sugar, '1000', '40');
        $brand = $this->pos->tenant(fn () => Brand::query()->findOrFail($this->pos->outlet->brand_id));
        $group = Menu::modifierGroup($company, $brand, [['name' => 'Extra Shot', 'price' => '5000'], ['name' => 'Tanpa Susu', 'price' => '0']], 0, 2, 'Tambahan');
        [$shot, $noMilk] = [$group->modifiers[0], $group->modifiers[1]];
        Stock::recipe($company, Recipe::MODIFIER, $shot->id, [[$this->beans, '9'], [$sugar, '5']]);
        Stock::recipe($company, Recipe::MODIFIER, $noMilk->id, [[$this->milk, '-500']]);
        $this->pos->backdate();
        $this->getJson('/api/v1/sync/pull', bearer($this->pos->deviceToken))->assertOk();

        $large = $this->pos->coffee->variants->firstWhere('name', 'Large');
        $order = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1), [
            'lines' => [[
                'id' => (string) Str::uuid7(), 'item_id' => $this->pos->coffee->id, 'variant_id' => $large->id, 'name' => 'Kopi Susu',
                'variant_name' => 'Large', 'qty' => 2, 'unit_price' => '22000',
                'modifiers' => [['id' => $shot->id, 'name' => 'Extra Shot', 'price' => '5000', 'qty' => 1], ['id' => $noMilk->id, 'name' => 'Tanpa Susu', 'price' => '0', 'qty' => 1]],
            ]],
            'totals' => ['subtotal' => '54000', 'item_discount' => '0', 'order_discount' => '0', 'service_charge' => '2700', 'tax' => '5670', 'rounding' => '30', 'total' => '62400'],
            'payments' => [['id' => (string) Str::uuid7(), 'method' => 'cash', 'amount' => '62400', 'tendered' => '62400', 'created_at' => now()->subMinutes(4)->toIso8601String()]],
        ]);
        expect($this->pos->push('order', $order)['status'])->toBe('accepted');

        // (18 + 9) × 2 = 54 g kopi; susu 200 − 500 < 0 → tidak dipotong; gula 5 × 2.
        expect(Stock::balance($company, $this->location, $this->beans)['qty'])->toBe('946.0000')
            ->and(Stock::balance($company, $this->location, $this->milk)['qty'])->toBe('5000.0000')
            ->and(Stock::balance($company, $this->location, $sugar)['qty'])->toBe('990.0000');
    });

    it('menjabarkan bahan setengah jadi dari sub-resepnya', function () {
        $company = $this->pos->company;
        $syrup = Stock::ingredient($company, 'Sirup Gula Aren', 'ml', ['kind' => Ingredient::SEMI]);
        $palm = Stock::ingredient($company, 'Gula Aren Blok', 'g');
        $water = Stock::ingredient($company, 'Air Mineral', 'ml');
        Stock::receive($company, $this->location, $palm, '2000', '30');
        // 500 g gula aren + 250 ml air → 600 ml sirup.
        Stock::recipe($company, Recipe::INGREDIENT, $syrup->id, [[$palm, '500'], [$water, '250']], '600');
        Stock::recipe($company, Recipe::ITEM, $this->pos->coffee->id, [[$this->beans, '18'], [$this->milk, '150'], [$syrup, '30']]);

        $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));

        expect(Stock::balance($company, $this->location, $palm)['qty'])->toBe('1975.0000')
            ->and(Stock::balance($company, $this->location, $water)['qty'])->toBe('-12.5000')
            ->and(Stock::balance($company, $this->location, $syrup)['qty'])->toBe('0.0000');
        expect(movements($this->pos, 'sale')->firstWhere('ingredient_id', $water->id)->flags)->toBe(['negative_stock']);
    });

    it('pembatalan sebelum bayar tidak memotong stok', function () {
        $order = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1), [
            'status' => 'voided', 'payments' => [],
            'void' => ['reason' => 'Pelanggan batal', 'voided_by' => $this->pos->userId('manager')],
        ]);
        $order['lines'][0]['sent_to_kitchen_at'] = now()->subMinutes(5)->toIso8601String();
        expect($this->pos->pushAs('manager', 'order', $order)['status'])->toBe('accepted');

        expect(movements($this->pos)->whereIn('type', ['sale', 'waste']))->toHaveCount(0);
    });

    it('kiriman ulang pesanan yang sama tidak memotong stok dua kali', function () {
        $payload = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1));
        $id = (string) Str::uuid7();
        $this->pos->push('order', $payload, $id);
        expect($this->pos->push('order', $payload, $id)['status'])->toBe('duplicate');
        $this->artisan('inventory:post-sales')->assertSuccessful();

        expect(movements($this->pos, 'sale'))->toHaveCount(3);
    });
});

describe('potong stok saat kirim dapur (on_kitchen)', function () {
    beforeEach(fn () => useTrigger($this->pos, 'on_kitchen'));

    it('memotong saat tiket diterima dan tidak memotong lagi saat bayar', function () {
        $orderId = (string) Str::uuid7();
        $order = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1));
        $croissantLine = $order['lines'][0]['id'];

        $ticket = $this->pos->pushAs('cashier', 'kitchen.send', ticketFor($order, $orderId, [$croissantLine]) + ['shift_id' => $this->shiftId, 'sent_by' => $this->pos->userId('cashier')]);
        expect($ticket['status'])->toBe('accepted');
        expect(Stock::balance($this->pos->company, $this->location, $this->dough)['qty'])->toBe('18.0000')
            ->and(Stock::balance($this->pos->company, $this->location, $this->beans)['qty'])->toBe('1000.0000');

        $order['lines'][0]['sent_to_kitchen_at'] = now()->subMinutes(6)->toIso8601String();
        expect($this->pos->push('order', $order, $orderId)['status'])->toBe('accepted');

        // Croissant tidak dipotong ulang; kopi (tanpa tiket) dipotong saat bayar.
        expect(Stock::balance($this->pos->company, $this->location, $this->dough)['qty'])->toBe('18.0000')
            ->and(Stock::balance($this->pos->company, $this->location, $this->beans)['qty'])->toBe('982.0000');
        $bySource = $this->pos->tenant(fn () => DB::table('stock_line_postings')->pluck('source', 'line_id'));
        expect($bySource[$croissantLine])->toBe('kitchen')->and($bySource[$order['lines'][1]['id']])->toBe('order');
        expect(movements($this->pos, 'sale')->firstWhere('ingredient_id', $this->dough->id)->reference_type)->toBe('kitchen_ticket');
    });

    it('pesanan yang dibatalkan setelah dikirim ke dapur dicatat sebagai waste', function () {
        $order = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1), [
            'status' => 'voided', 'payments' => [],
            'void' => ['reason' => 'Pelanggan pergi', 'voided_by' => $this->pos->userId('manager')],
        ]);
        $order['lines'][0]['sent_to_kitchen_at'] = now()->subMinutes(5)->toIso8601String();
        expect($this->pos->pushAs('manager', 'order', $order)['status'])->toBe('accepted');

        $waste = movements($this->pos, 'waste');
        expect($waste)->toHaveCount(1)
            ->and($waste->first()->ingredient_id)->toBe($this->dough->id)
            ->and($waste->first()->qty)->toBe('-2.0000')
            ->and($waste->first()->reason)->toBe('Pesanan dibatalkan setelah dikirim ke dapur');
        expect(movements($this->pos, 'sale'))->toHaveCount(0);
    });

    it('tiket dapur ditolak untuk menu brand lain atau tanpa pengirim yang sah', function () {
        [$other, $otherOwner] = Factory::company('Warung Bu Ratna');
        $otherItem = Menu::item($other, Factory::brand($other), ['name' => 'Es Teh Manis']);
        $bad = [
            'order_id' => (string) Str::uuid7(), 'sent_by' => $this->pos->userId('cashier'), 'sent_at' => now()->toIso8601String(),
            'lines' => [['id' => (string) Str::uuid7(), 'item_id' => $otherItem->id, 'qty' => 1]],
        ];
        expect($this->pos->pushAs('cashier', 'kitchen.send', $bad)['error']['code'])->toBe('ITEM_UNKNOWN');

        $kitchenOnly = $bad;
        $kitchenOnly['lines'][0]['item_id'] = $this->pos->croissant->id;
        $kitchenOnly['sent_by'] = $this->pos->userId('kitchen');
        expect($this->pos->push('kitchen.send', $kitchenOnly)['status'])->toBe('rejected');
        expect(movements($this->pos, 'sale'))->toHaveCount(0);
    });

    it('endpoint online kirim ke dapur memakai kasir yang login', function () {
        $token = $this->pos->token('cashier');
        $body = [
            'id' => (string) Str::uuid7(), 'order_id' => (string) Str::uuid7(), 'shift_id' => $this->shiftId,
            'lines' => [['id' => (string) Str::uuid7(), 'item_id' => $this->pos->croissant->id, 'qty' => 3]],
        ];
        $this->postJson('/api/v1/pos/kitchen-tickets', $body, bearer($token))
            ->assertCreated()->assertJsonPath('data.ticket_id', $body['id'])->assertJsonPath('meta.sync_status', 'accepted');
        $this->postJson('/api/v1/pos/kitchen-tickets', $body, bearer($token))
            ->assertOk()->assertJsonPath('meta.sync_status', 'duplicate');
        expect(Stock::balance($this->pos->company, $this->location, $this->dough)['qty'])->toBe('17.0000');

        // Token perangkat saja tidak cukup (pelaku harus login PIN).
        $this->postJson('/api/v1/pos/kitchen-tickets', $body, bearer($this->pos->deviceToken))->assertForbidden();
    });
});

describe('void & refund mengembalikan stok', function () {
    beforeEach(function () {
        useTrigger($this->pos, 'on_payment');
        $this->payload = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1));
        $this->orderId = $this->pos->push('order', $this->payload)['order_id'];
        // HPP berubah setelah penjualan: pengembalian tetap memakai HPP saat dipotong.
        Stock::receive($this->pos->company, $this->location, $this->dough, '18', '12000');
    });

    it('void setelah bayar mengembalikan bahan dengan HPP saat terjual', function () {
        $result = $this->pos->pushAs('manager', 'order.void', ['order_id' => $this->orderId, 'voided_by' => $this->pos->userId('manager'), 'reason' => 'Salah input', 'created_at' => now()->toIso8601String()]);
        expect($result['status'])->toBe('accepted');

        $returns = movements($this->pos, 'sale_return');
        expect($returns)->toHaveCount(3);
        $dough = $returns->firstWhere('ingredient_id', $this->dough->id);
        expect($dough->qty)->toBe('2.0000')->and($dough->unit_cost)->toBe('9000.000000')->and($dough->reference_type)->toBe('order_void');
        expect(Stock::balance($this->pos->company, $this->location, $this->dough)['qty'])->toBe('38.0000')
            ->and(Stock::balance($this->pos->company, $this->location, $this->beans)['qty'])->toBe('1000.0000');
    });

    it('void dengan pilihan waste tidak mengembalikan bahan', function () {
        $this->pos->pushAs('manager', 'order.void', ['order_id' => $this->orderId, 'voided_by' => $this->pos->userId('manager'), 'reason' => 'Minuman tumpah', 'stock_action' => 'waste', 'created_at' => now()->toIso8601String()]);

        expect(movements($this->pos, 'sale_return'))->toHaveCount(0);
        expect($this->pos->tenant(fn () => DB::table('orders')->where('id', $this->orderId)->value('void_stock_action')))->toBe('waste');
    });

    it('refund sebagian lalu refund penuh mengembalikan sisa; refund waste tidak', function () {
        $croissant = $this->payload['lines'][0]['id'];
        $first = $this->pos->pushAs('manager', 'order.refund', [
            'order_id' => $this->orderId, 'shift_id' => $this->shiftId, 'amount' => '28860.29', 'method' => 'cash', 'stock_action' => 'return',
            'reason' => 'Croissant gosong', 'refunded_by' => $this->pos->userId('manager'), 'created_at' => now()->toIso8601String(),
            'lines' => [['order_item_id' => $croissant, 'qty' => 1]],
        ]);
        expect($first['status'])->toBe('accepted');
        expect(Stock::balance($this->pos->company, $this->location, $this->dough)['qty'])->toBe('37.0000');

        $rest = $this->pos->pushAs('manager', 'order.refund', [
            'order_id' => $this->orderId, 'shift_id' => $this->shiftId, 'amount' => '49639.71', 'method' => 'cash', 'stock_action' => 'return',
            'reason' => 'Pelanggan komplain', 'refunded_by' => $this->pos->userId('manager'), 'created_at' => now()->toIso8601String(),
        ]);
        expect($rest['status'])->toBe('accepted');
        expect(Stock::balance($this->pos->company, $this->location, $this->dough)['qty'])->toBe('38.0000')
            ->and(Stock::balance($this->pos->company, $this->location, $this->beans)['qty'])->toBe('1000.0000');
        expect($this->pos->tenant(fn () => DB::table('stock_line_postings')->where('line_id', $croissant)->value('returned_qty')))->toBe('2.000');
    });

    it('refund dengan waste tidak mengembalikan stok', function () {
        $this->pos->pushAs('manager', 'order.refund', [
            'order_id' => $this->orderId, 'shift_id' => $this->shiftId, 'amount' => '78500', 'method' => 'cash', 'stock_action' => 'waste',
            'reason' => 'Makanan sudah dimakan sebagian', 'refunded_by' => $this->pos->userId('manager'), 'created_at' => now()->toIso8601String(),
        ]);

        expect(movements($this->pos, 'sale_return'))->toHaveCount(0);
    });
});

describe('keandalan posting', function () {
    beforeEach(fn () => useTrigger($this->pos, 'on_payment'));

    it('kegagalan posting tidak membatalkan penjualan dan diulang oleh perintah terjadwal', function () {
        $broken = Mockery::mock(RecipeExplorer::class);
        $broken->shouldReceive('prepare')->andThrow(new RuntimeException('Gangguan sementara'));
        app()->instance(RecipeExplorer::class, $broken);

        $result = $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));
        expect($result['status'])->toBe('accepted');
        $row = $this->pos->tenant(fn () => DB::table('stock_event_postings')->where('key', 'completed:'.$result['order_id'])->first());
        expect($row->status)->toBe('failed')->and($row->attempts)->toBe(1)->and($row->last_error)->toBe('Gangguan sementara');
        expect(movements($this->pos))->toHaveCount(3); // hanya saldo awal

        app()->forgetInstance(RecipeExplorer::class);
        $this->artisan('inventory:post-sales')->assertSuccessful();

        expect(movements($this->pos, 'sale'))->toHaveCount(3);
        expect($this->pos->tenant(fn () => DB::table('stock_event_postings')->where('key', 'completed:'.$result['order_id'])->value('status')))->toBe('posted');
    });

    it('memberi sinyal saat stok turun di bawah minimum (FR-INV-08)', function () {
        $this->pos->tenant(fn () => Ingredient::query()->whereKey($this->dough->id)->update(['min_stock' => '19']));
        Event::fake([StockBelowMinimum::class]);

        $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));

        Event::assertDispatched(StockBelowMinimum::class, fn (StockBelowMinimum $e) => $e->ingredientId === $this->dough->id && $e->qty === '18.0000' && $e->minQty === '19.0000');
        Event::assertDispatchedTimes(StockBelowMinimum::class, 1);
    });

    it('stok bahan terisolasi per company (RLS)', function () {
        [$other] = Factory::company('Warung Bu Ratna');
        $count = Factory::tenant($other, fn () => StockMovement::query()->count());
        $raw = Factory::tenant($other, fn () => DB::table('stock_balances')->count());
        expect($count)->toBe(0)->and($raw)->toBe(0);
    });
});
