<?php

use App\Filament\Resources\ItemResource\Pages\EditItem;
use App\Filament\Resources\ItemResource\RelationManagers\PricesRelationManager;
use App\Modules\Catalog\Application\ItemWriter;
use App\Modules\Catalog\Application\PromotionWriter;
use App\Modules\Catalog\Domain\Models\ItemPrice;
use App\Modules\Catalog\Domain\Models\Promotion;
use App\Modules\Tenancy\Application\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Factory;
use Tests\Support\Menu;

/** Regresi temuan review keamanan Tahap 2. */
beforeEach(function () {
    [$this->a, $this->ownerA] = Factory::company('Kopi Tepi Jalan');
    $this->brandA = Factory::brand($this->a, ['code' => 'KTJ']);
    $this->kemang = Factory::outlet($this->a, $this->brandA, ['code' => 'KMG']);
    [$this->b] = Factory::company('Warung Bu Ratna');
    $this->brandB = Factory::brand($this->b, ['code' => 'WBR']);
    $this->outletB = Factory::outlet($this->b, $this->brandB, ['code' => 'TLG']);
    $this->channelB = Menu::channel($this->b, 'gofood');
    $this->headers = asMember($this->ownerA, $this->a);
});

it('menolak ID channel/varian company lain di panel harga khusus', function () {
    $item = Menu::item($this->a, $this->brandA, ['variants' => [['name' => 'Regular', 'price' => '18000']]]);
    $foreignItem = Menu::item($this->b, $this->brandB, ['variants' => [['name' => 'Besar', 'price' => '1']]]);
    $this->actingAs($this->ownerA);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->a);
    app(TenantContext::class)->setTenant($this->a->id);
    $variant = $item->variants->first();

    $rm = Livewire::test(PricesRelationManager::class, ['ownerRecord' => $item, 'pageClass' => EditItem::class]);
    $rm->callTableAction('create', data: ['item_variant_id' => $variant->id, 'sales_channel_id' => $this->channelB->id, 'price' => '1'])
        ->assertHasTableActionErrors(['sales_channel_id']);
    $rm->callTableAction('create', data: ['item_variant_id' => $foreignItem->variants->first()->id, 'outlet_id' => $this->kemang->id, 'price' => '1'])
        ->assertHasTableActionErrors(['item_variant_id']);

    expect(ItemPrice::query()->count())->toBe(0);
    app(TenantContext::class)->reset();
    expect(Factory::system(fn () => ItemPrice::query()->withoutGlobalScopes()->count()))->toBe(0);
});

it('menolak ID outlet/menu/kategori company lain di promo, termasuk promo seluruh company', function (array $refs) {
    $refs = array_map(fn ($key) => match ($key) {
        'outlet' => ['outlet_ids' => [$this->outletB->id]],
        'item' => ['item_ids' => [Menu::item($this->b, $this->brandB)->id], 'scope' => 'items'],
        'category' => ['category_ids' => [Menu::category($this->b, $this->brandB)->id], 'scope' => 'items'],
        'bukan-uuid' => ['item_ids' => ['bukan-uuid'], 'scope' => 'items'],
    }, $refs)[0];

    foreach ([null, $this->brandA->id] as $brandId) {
        $save = fn () => Factory::tenant($this->a, fn () => app(PromotionWriter::class)->save($this->ownerA, new Promotion, $refs + [
            'brand_id' => $brandId, 'name' => 'Curang', 'type' => 'percent', 'value' => '10', 'starts_at' => now(),
        ]));
        expect($save)->toThrow(ValidationException::class);
    }
    expect(Factory::system(fn () => DB::table('promotion_outlets')->count() + DB::table('promotion_targets')->count()))->toBe(0);
})->with([[['outlet']], [['item']], [['category']], [['bukan-uuid']]]);

it('menolak jenis menu dan channel tak dikenal di ItemWriter', function (array $override, string $field) {
    $category = Menu::category($this->a, $this->brandA);
    $save = fn () => Factory::tenant($this->a, fn () => app(ItemWriter::class)->save(null, $override + [
        'brand_id' => $this->brandA->id, 'category_id' => $category->id, 'sku' => 'X-1', 'name' => 'X', 'base_price' => '1',
    ]));

    try {
        $save();
        $this->fail('Seharusnya ditolak');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'jenis ketiga' => [['type' => 'combo'], 'type'],
    'channel tidak ada' => [['channel_codes' => ['tokopedia']], 'channel_codes'],
]);

it('menolak angka tidak lazim dan diskon > 100% pada simulasi harga dengan 422', function (array $patch) {
    $item = Menu::item($this->a, $this->brandA);
    $body = array_replace_recursive([
        'outlet_id' => $this->kemang->id,
        'lines' => [['item_id' => $item->id, 'qty' => '1']],
    ], $patch);

    $this->postJson('/api/v1/quotes', $body, $this->headers)->assertUnprocessable();
})->with([
    'qty +5' => [['lines' => [['qty' => '+5']]]],
    'qty .5' => [['lines' => [['qty' => '.5']]]],
    'qty 5.' => [['lines' => [['qty' => '5.']]]],
    'diskon item 150%' => [['lines' => [['discounts' => [['type' => 'percent', 'value' => '150']]]]]],
    'diskon order 150%' => [['order_discounts' => [['type' => 'percent', 'value' => '150']]]],
    'diskon nominal .5' => [['order_discounts' => [['type' => 'amount', 'value' => '.5']]]],
    'id baris ganda' => [['lines' => [['id' => 'a'], ['id' => 'a', 'item_id' => null]]]],
]);

it('tidak mengirim kode promo asli ke perangkat POS', function () {
    $created = Menu::promotion($this->a, ['name' => 'Kode', 'code' => 'RAHASIA50', 'auto_apply' => false]);
    [, $token] = Factory::pairedDevice($this->a, $this->kemang);

    $promo = $this->getJson('/api/v1/pos/catalog', bearer($token))->assertOk()->json('data.promotions.0');

    // Hash tersimpan ikut berubah saat kode diganti dan hilang saat kode dikosongkan.
    Factory::tenant($this->a, fn () => $created->update(['code' => 'BARU10']));
    expect($created->refresh()->code_hash)->toBe(Promotion::hashCode($created->id, 'BARU10'));
    Factory::tenant($this->a, fn () => $created->update(['code' => null, 'auto_apply' => true]));
    expect($created->refresh()->code_hash)->toBeNull();

    expect($promo)->not->toHaveKey('code')
        ->and($promo['code_hash'])->toBe('pbkdf2_sha256$100000$'.hash_pbkdf2('sha256', 'RAHASIA50', $created->id, 100000, 64))
        ->and(Promotion::hashCode($created->id, ' rahasia50 '))->toBe($promo['code_hash'])
        ->and(json_encode($promo))->not->toContain('RAHASIA50');
});

it('mewajibkan kasir berwenang untuk diskon manual di simulasi POS', function () {
    $item = Menu::item($this->a, $this->brandA);
    [, $deviceToken] = Factory::pairedDevice($this->a, $this->kemang);
    [, $cashier] = Factory::staff($this->a, ['cashier'], [$this->kemang->id], '7351');
    [, $manager] = Factory::staff($this->a, ['outlet_manager'], [$this->kemang->id], '482915');
    $login = fn ($member, $pin) => $this->postJson('/api/v1/pos/auth/pin', ['staff_id' => $member->id, 'pin' => $pin], bearer($deviceToken))->json('data.token');
    $cart = ['lines' => [['item_id' => $item->id, 'qty' => 1]], 'order_discounts' => [['type' => 'percent', 'value' => '10']]];

    $this->postJson('/api/v1/pos/quotes', $cart, bearer($deviceToken))->assertForbidden();
    $this->postJson('/api/v1/pos/quotes', $cart, bearer($login($cashier, '7351')))->assertForbidden();
    $this->postJson('/api/v1/pos/quotes', $cart, bearer($login($manager, '482915')))->assertOk();
    // Tanpa diskon manual, token perangkat boleh menghitung.
    $this->postJson('/api/v1/pos/quotes', ['lines' => $cart['lines']], bearer($deviceToken))->assertOk();
});

describe('TenantContext', function () {
    afterEach(fn () => app(TenantContext::class)->reset());

    it('menerapkan ulang konteks setelah transaksi yang mengubah konteks dibatalkan', function () {
        $context = app(TenantContext::class);
        $context->runAsSystem(function () use ($context): void {
            DB::beginTransaction();
            $context->setTenant($this->a->id);   // berubah di dalam transaksi...
            DB::rollBack();                      // ...lalu dibatalkan: PostgreSQL mengembalikan role pemilik
            expect(DB::selectOne('select current_user as u')->u)->toBe('fnb_app')
                ->and(DB::selectOne("select current_setting('app.current_company_id', true) as c")->c)->toBe($this->a->id);
        });
    });

    it('mengembalikan tim permission walau pemulihan koneksi gagal', function () {
        $context = app(TenantContext::class);
        $context->setTenant($this->a->id);

        DB::beginTransaction(); // savepoint agar transaksi uji bisa dipulihkan
        expect(fn () => $context->runAsTenant($this->b->id, function (): void {
            DB::statement('select 1/0'); // membatalkan transaksi → pemulihan konteks ikut gagal
        }))->toThrow(QueryException::class, 'division by zero');

        // Galat asli yang dilempar, dan tim permission tetap kembali ke company A.
        expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($this->a->id);
        DB::rollBack();

        // Setelah rollback, konteks diterapkan ulang: role RLS & company A.
        expect(DB::selectOne('select current_user as u')->u)->toBe('fnb_app')
            ->and(DB::selectOne("select current_setting('app.current_company_id', true) as c")->c)->toBe($this->a->id);
    });
});
