<?php

use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemPriceHistory;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Factory;
use Tests\Support\Menu;

beforeEach(function () {
    [$this->a, $this->ownerA] = Factory::company('Kopi Tepi Jalan');
    $this->brandA = Factory::brand($this->a, ['code' => 'KTJ']);
    $this->outletA = Factory::outlet($this->a, $this->brandA, ['code' => 'KMG']);

    [$this->b, $this->ownerB] = Factory::company('Warung Bu Ratna');
    $this->brandB = Factory::brand($this->b, ['code' => 'WBR']);
    $this->outletB = Factory::outlet($this->b, $this->brandB, ['code' => 'TLG']);
    $this->itemB = Menu::item($this->b, $this->brandB, ['name' => 'Nasi Rawon', 'base_price' => '28000']);
    $this->groupB = Menu::modifierGroup($this->b, $this->brandB, [['name' => 'Sambal', 'price' => '0']]);
    $this->promoB = Menu::promotion($this->b, ['brand_id' => $this->brandB->id]);
    $this->stationB = Menu::station($this->b);
    $this->channelB = Menu::channel($this->b, 'gofood');
});

it('menolak akses data menu company lain dengan 404 (SRS §10.1)', function (string $method, string $uri) {
    $uri = strtr($uri, [
        '{item}' => $this->itemB->id,
        '{category}' => $this->itemB->category_id,
        '{group}' => $this->groupB->id,
        '{promotion}' => $this->promoB->id,
        '{station}' => $this->stationB->id,
        '{channel}' => $this->channelB->id,
    ]);

    $response = $this->json($method, $uri, ['name' => 'Coba', 'prices' => [], 'is_sold_out' => true], asMember($this->ownerA, $this->a));

    $response->assertNotFound();
    assertStandardEnvelope($response, false);
    expect(firstErrorCode($response))->toBe('NOT_FOUND');
})->with([
    ['GET', '/api/v1/items/{item}'],
    ['PATCH', '/api/v1/items/{item}'],
    ['DELETE', '/api/v1/items/{item}'],
    ['GET', '/api/v1/items/{item}/prices'],
    ['PUT', '/api/v1/items/{item}/prices'],
    ['GET', '/api/v1/items/{item}/price-history'],
    ['GET', '/api/v1/items/{item}/availability'],
    ['PUT', '/api/v1/items/{item}/availability'],
    ['GET', '/api/v1/menu-categories/{category}'],
    ['PATCH', '/api/v1/menu-categories/{category}'],
    ['DELETE', '/api/v1/menu-categories/{category}'],
    ['GET', '/api/v1/modifier-groups/{group}'],
    ['PATCH', '/api/v1/modifier-groups/{group}'],
    ['DELETE', '/api/v1/modifier-groups/{group}'],
    ['GET', '/api/v1/promotions/{promotion}'],
    ['PATCH', '/api/v1/promotions/{promotion}'],
    ['DELETE', '/api/v1/promotions/{promotion}'],
    ['PATCH', '/api/v1/kitchen-stations/{station}'],
    ['DELETE', '/api/v1/kitchen-stations/{station}'],
    ['PATCH', '/api/v1/sales-channels/{channel}'],
]);

it('tidak menampilkan data menu company lain di daftar', function (string $uri) {
    $response = $this->getJson($uri, asMember($this->ownerA, $this->a))->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->not->toContain($this->itemB->id, $this->itemB->category_id, $this->groupB->id, $this->promoB->id, $this->stationB->id, $this->channelB->id);
})->with([
    '/api/v1/items', '/api/v1/menu-categories', '/api/v1/modifier-groups', '/api/v1/promotions',
    '/api/v1/kitchen-stations', '/api/v1/sales-channels',
]);

it('menolak referensi lintas company saat membuat data menu', function () {
    $headers = asMember($this->ownerA, $this->a);

    $response = $this->postJson('/api/v1/items', [
        'brand_id' => $this->brandB->id, 'category_id' => $this->itemB->category_id,
        'sku' => 'X-1', 'name' => 'Curang', 'base_price' => '1000',
    ], $headers)->assertUnprocessable();
    expect(errorFields($response))->toContain('brand_id');

    $categoryA = Menu::category($this->a, $this->brandA);
    $this->postJson('/api/v1/items', [
        'brand_id' => $this->brandA->id, 'category_id' => $categoryA->id,
        'sku' => 'X-2', 'name' => 'Curang', 'base_price' => '1000',
        'modifier_group_ids' => [$this->groupB->id],
    ], $headers)->assertUnprocessable();

    $this->postJson('/api/v1/quotes', [
        'outlet_id' => $this->outletB->id,
        'lines' => [['item_id' => $this->itemB->id, 'qty' => 1]],
    ], $headers)->assertNotFound();

    $itemA = Menu::item($this->a, $this->brandA);
    $this->postJson('/api/v1/quotes', [
        'outlet_id' => $this->outletA->id,
        'lines' => [['item_id' => $this->itemB->id, 'qty' => 1]],
    ], $headers)->assertUnprocessable();

    $this->putJson("/api/v1/items/{$itemA->id}/prices", [
        'prices' => [['outlet_id' => $this->outletB->id, 'price' => '1']],
    ], $headers)->assertUnprocessable();

    $response = $this->postJson('/api/v1/promotions', [
        'name' => 'Curang', 'type' => 'percent', 'value' => '10', 'starts_at' => now()->toIso8601String(),
        'scope' => 'items', 'item_ids' => [$this->itemB->id],
    ], $headers)->assertUnprocessable();
    expect(errorFields($response))->toContain('item_ids.0');

    $this->postJson('/api/v1/menu/copy-brand', ['from_brand_id' => $this->brandB->id, 'to_brand_id' => $this->brandA->id], $headers)
        ->assertNotFound();
});

it('menerapkan RLS pada tabel menu baru', function (string $table) {
    $context = app(TenantContext::class);

    $visible = $context->runAsTenant($this->a->id, fn () => DB::table($table)->where('company_id', $this->b->id)->count());
    expect($visible)->toBe(0);

    $total = $context->runAsSystem(fn () => DB::table($table)->where('company_id', $this->b->id)->count());
    if (in_array($table, ['menu_categories', 'items', 'modifier_groups', 'modifiers', 'promotions', 'kitchen_stations', 'sales_channels', 'item_price_histories'], true)) {
        expect($total)->toBeGreaterThan(0);
    }
})->with([
    'kitchen_stations', 'sales_channels', 'menu_categories', 'items', 'item_variants', 'modifier_groups', 'modifiers',
    'item_modifier_groups', 'bundle_groups', 'bundle_group_options', 'item_prices', 'item_price_histories',
    'outlet_item_availability', 'promotions', 'promotion_targets', 'promotion_outlets',
]);

it('RLS menolak penulisan data dengan company lain', function () {
    expect(fn () => app(TenantContext::class)->runAsTenant($this->a->id, fn () => DB::transaction(fn () => DB::table('menu_categories')->insert([
        'id' => (string) Str::uuid7(), 'company_id' => $this->b->id, 'brand_id' => $this->brandB->id,
        'name' => 'Sisipan', 'created_at' => now(), 'updated_at' => now(),
    ]))))->toThrow(QueryException::class, 'row-level security');
});

it('riwayat harga tidak dapat diubah atau dihapus (append-only)', function () {
    $history = Factory::tenant($this->b, fn () => ItemPriceHistory::query()->where('item_id', $this->itemB->id)->firstOrFail());
    expect($history->new_price)->toBe('28000.00');

    // Tiap percobaan dibungkus savepoint agar transaksi uji tetap bisa dipakai.
    $attempt = fn (string $mode, Closure $write) => fn () => $mode === 'tenant'
        ? Factory::tenant($this->b, fn () => DB::transaction($write))
        : Factory::system(fn () => DB::transaction($write));
    $table = fn () => DB::table('item_price_histories')->where('id', $history->id);

    expect($attempt('tenant', fn () => $table()->update(['new_price' => 1])))->toThrow(QueryException::class);
    expect($attempt('tenant', fn () => $table()->delete()))->toThrow(QueryException::class);
    expect($attempt('system', fn () => $table()->update(['new_price' => 1])))->toThrow(QueryException::class);
    expect($attempt('system', fn () => $table()->delete()))->toThrow(QueryException::class);

    expect(Factory::tenant($this->b, fn () => Item::query()->count()))->toBe(1);
});
