<?php

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\Recipe;
use App\Modules\Inventory\Domain\Models\StockLocation;
use Illuminate\Support\Facades\DB;
use Tests\Support\Factory;
use Tests\Support\Pos;
use Tests\Support\Stock;

beforeEach(function () {
    $this->pos = Pos::setup();
    $this->owner = Factory::ownerOf($this->pos->company);
    $this->headers = asMember($this->owner, $this->pos->company);
});

describe('bahan baku (FR-INV-01)', function () {
    it('membuat, menampilkan, mengubah bahan beserta satuan beli', function () {
        $response = $this->postJson('/api/v1/ingredients', [
            'code' => 'SUSU-UHT', 'name' => 'Susu UHT Full Cream', 'category' => 'Susu & Krim', 'base_unit' => 'ml', 'min_stock' => '5000',
            'units' => [['name' => 'karton', 'factor' => '12000', 'is_purchase_default' => true], ['name' => 'kotak', 'factor' => '1000']],
        ], $this->headers);

        $response->assertCreated();
        assertStandardEnvelope($response);
        $response->assertJsonPath('data.code', 'SUSU-UHT')
            ->assertJsonPath('data.kind', 'raw')
            ->assertJsonPath('data.min_stock', '5000.0000')
            ->assertJsonPath('data.units.0.name', 'kotak')
            ->assertJsonPath('data.units.1.is_purchase_default', true)
            ->assertJsonPath('data.has_recipe', false);
        $id = $response->json('data.id');

        $this->getJson('/api/v1/ingredients?search=uht', $this->headers)->assertOk()->assertJsonPath('meta.pagination.total', 1);

        $this->patchJson("/api/v1/ingredients/{$id}", ['name' => 'Susu UHT Full Cream 1L', 'units' => [['name' => 'karton', 'factor' => '12000']]], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.name', 'Susu UHT Full Cream 1L')
            ->assertJsonCount(1, 'data.units');
        expect($this->pos->tenant(fn () => AuditLog::query()->where('action', 'ingredient.units_updated')->count()))->toBe(1);

        // Satuan dasar tidak dapat diubah; kode unik tanpa membedakan huruf.
        $this->patchJson("/api/v1/ingredients/{$id}", ['base_unit' => 'g'], $this->headers)->assertUnprocessable();
        $this->postJson('/api/v1/ingredients', ['code' => 'susu-uht', 'name' => 'Duplikat', 'base_unit' => 'ml'], $this->headers)
            ->assertUnprocessable()->assertJsonPath('errors.0.field', 'code');
    });

    it('memvalidasi input bahan', function () {
        $response = $this->postJson('/api/v1/ingredients', [
            'code' => 'kode salah!', 'name' => '', 'base_unit' => 'liter', 'min_stock' => '-1',
            'units' => [['name' => 'g', 'factor' => '0']],
        ], $this->headers);

        $response->assertUnprocessable();
        expect(errorFields($response))->toContain('code', 'name', 'base_unit', 'min_stock', 'units.0.name', 'units.0.factor');
    });

    it('menolak hapus bahan yang dipakai resep atau masih bersaldo', function () {
        $beans = Stock::ingredient($this->pos->company, 'Biji Kopi Robusta', 'g');
        Stock::recipe($this->pos->company, Recipe::ITEM, $this->pos->coffee->id, [[$beans, '18']]);
        $this->deleteJson("/api/v1/ingredients/{$beans->id}", [], $this->headers)->assertStatus(409)->assertJsonPath('errors.0.code', 'INGREDIENT_IN_USE');

        $sugar = Stock::ingredient($this->pos->company, 'Gula Pasir', 'g');
        Stock::receive($this->pos->company, Stock::location($this->pos->company, $this->pos->outlet), $sugar, '1000', '15');
        $this->deleteJson("/api/v1/ingredients/{$sugar->id}", [], $this->headers)->assertStatus(409)->assertJsonPath('errors.0.code', 'INGREDIENT_HAS_STOCK');

        $unused = Stock::ingredient($this->pos->company, 'Daun Pandan', 'lembar');
        $this->deleteJson("/api/v1/ingredients/{$unused->id}", [], $this->headers)->assertOk();
        expect($this->pos->tenant(fn () => Ingredient::withTrashed()->find($unused->id)->trashed()))->toBeTrue();
    });

    it('hanya pengelola inventory tingkat company yang mengubah data master', function () {
        [$warehouse] = Factory::staff($this->pos->company, ['warehouse']);
        $this->postJson('/api/v1/ingredients', ['code' => 'TPG', 'name' => 'Tepung Terigu', 'base_unit' => 'g'], asMember($warehouse, $this->pos->company))->assertCreated();

        // Manajer outlet (dibatasi outlet) & manajer brand hanya melihat.
        foreach (['manager' => $this->pos->staff['manager']['user'], 'brand' => Factory::staff($this->pos->company, ['brand_manager'])[0]] as $user) {
            $h = asMember($user, $this->pos->company);
            $this->getJson('/api/v1/ingredients', $h)->assertOk();
            $this->postJson('/api/v1/ingredients', ['code' => 'X1', 'name' => 'Bahan X', 'base_unit' => 'g'], $h)->assertForbidden();
        }

        // Kasir & dapur tidak punya akses inventory.
        $this->getJson('/api/v1/ingredients', asMember($this->pos->staff['cashier']['user'], $this->pos->company))->assertForbidden();
        $this->getJson('/api/v1/ingredients', asMember($this->pos->staff['kitchen']['user'], $this->pos->company))->assertForbidden();
    });

    it('isolasi tenant: bahan company lain tidak dapat diakses', function () {
        $beans = Stock::ingredient($this->pos->company, 'Biji Kopi Arabika', 'g');
        [$other, $otherOwner] = Factory::company('Warung Bu Ratna');
        $h = asMember($otherOwner, $other);

        $this->getJson("/api/v1/ingredients/{$beans->id}", $h)->assertNotFound();
        $this->patchJson("/api/v1/ingredients/{$beans->id}", ['name' => 'Diubah'], $h)->assertNotFound();
        $this->deleteJson("/api/v1/ingredients/{$beans->id}", [], $h)->assertNotFound();
        $this->getJson('/api/v1/ingredients', $h)->assertOk()->assertJsonPath('meta.pagination.total', 0);
        expect($this->pos->tenant(fn () => $beans->refresh()->name))->toBe('Biji Kopi Arabika');
        $this->getJson('/api/v1/ingredients', asMember($otherOwner, $this->pos->company))->assertForbidden();
    });
});

describe('lokasi stok (FR-INV-02)', function () {
    it('outlet baru otomatis punya gudang utama; lokasi tambahan bisa dipetakan ke stasiun dapur', function () {
        $list = $this->getJson('/api/v1/stock-locations?outlet_id='.$this->pos->outlet->id, $this->headers)->assertOk();
        $list->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'UTAMA')->assertJsonPath('data.0.is_default', true);
        $mainId = $list->json('data.0.id');

        $station = $this->pos->tenant(fn () => DB::table('kitchen_stations')->where('code', 'BAR')->value('id'));
        $bar = $this->postJson('/api/v1/stock-locations', [
            'outlet_id' => $this->pos->outlet->id, 'code' => 'BAR', 'name' => 'Bar Kopi', 'kitchen_station_id' => $station,
        ], $this->headers)->assertCreated()->assertJsonPath('data.kitchen_station_id', $station);

        $this->postJson('/api/v1/stock-locations', ['outlet_id' => $this->pos->outlet->id, 'code' => 'BAR2', 'name' => 'Bar 2', 'kitchen_station_id' => $station], $this->headers)
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'LOCATION_CONFLICT');

        $this->patchJson("/api/v1/stock-locations/{$mainId}", ['is_active' => false], $this->headers)
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'DEFAULT_LOCATION');

        // Pindahkan lokasi utama.
        $this->patchJson('/api/v1/stock-locations/'.$bar->json('data.id'), ['is_default' => true], $this->headers)->assertOk()->assertJsonPath('data.is_default', true);
        expect($this->pos->tenant(fn () => StockLocation::query()->find($mainId)->is_default))->toBeFalse();
    });

    it('manajer outlet lain tidak dapat menambah lokasi di outlet ini', function () {
        [$dago] = Factory::staff($this->pos->company, ['outlet_manager'], [$this->pos->otherOutlet->id]);
        $h = asMember($dago, $this->pos->company);
        $this->postJson('/api/v1/stock-locations', ['outlet_id' => $this->pos->outlet->id, 'code' => 'DPR', 'name' => 'Dapur'], $h)->assertForbidden();
        $this->getJson('/api/v1/stock-locations', $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.outlet_id', $this->pos->otherOutlet->id);

        [$other, $otherOwner] = Factory::company('Warung Bu Ratna');
        $this->postJson('/api/v1/stock-locations', ['outlet_id' => $this->pos->outlet->id, 'code' => 'X', 'name' => 'X'], asMember($otherOwner, $other))
            ->assertUnprocessable()->assertJsonPath('errors.0.field', 'outlet_id');
    });
});

describe('resep (FR-INV-03)', function () {
    beforeEach(function () {
        $this->beans = Stock::ingredient($this->pos->company, 'Biji Kopi Arabika', 'g');
        $this->milk = Stock::ingredient($this->pos->company, 'Susu Segar', 'ml');
        Stock::receive($this->pos->company, Stock::location($this->pos->company, $this->pos->outlet), $this->beans, '1000', '250');
        Stock::receive($this->pos->company, Stock::location($this->pos->company, $this->pos->outlet), $this->milk, '1000', '18');
    });

    it('menyimpan resep menu dan menghitung HPP teoritis per porsi', function () {
        $url = '/api/v1/recipes/item/'.$this->pos->coffee->id;
        $this->getJson($url, $this->headers)->assertOk()->assertJsonPath('data.exists', false)->assertJsonPath('data.lines', []);

        $this->putJson($url, ['notes' => 'Espresso double', 'lines' => [
            ['ingredient_id' => $this->beans->id, 'qty' => '18'],
            ['ingredient_id' => $this->milk->id, 'qty' => '150'],
        ]], $this->headers)->assertOk()->assertJsonPath('data.exists', true)->assertJsonCount(2, 'data.lines');

        $show = $this->getJson($url.'?outlet_id='.$this->pos->outlet->id, $this->headers)->assertOk();
        $show->assertJsonPath('data.target_label', 'Kopi Susu')
            ->assertJsonPath('data.cost.total', '7200.00')
            ->assertJsonPath('data.cost.basis', 'outlet_average')
            ->assertJsonPath('data.cost.missing_cost', false);
        expect($this->pos->tenant(fn () => AuditLog::query()->where('action', 'recipe.created')->count()))->toBe(1);

        // Kosongkan baris = hapus resep.
        $this->putJson($url, ['lines' => []], $this->headers)->assertOk()->assertJsonPath('data.exists', false);
        expect($this->pos->tenant(fn () => Recipe::query()->count()))->toBe(0);
    });

    it('menolak jumlah minus selain modifier dan sub-resep yang saling merujuk', function () {
        $this->putJson('/api/v1/recipes/item/'.$this->pos->coffee->id, ['lines' => [['ingredient_id' => $this->beans->id, 'qty' => '-5']]], $this->headers)
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'INVALID_QTY');

        $base = Stock::ingredient($this->pos->company, 'Base Cold Brew', 'ml', ['kind' => Ingredient::SEMI]);
        $blend = Stock::ingredient($this->pos->company, 'Cold Brew Blend', 'ml', ['kind' => Ingredient::SEMI]);
        $this->putJson("/api/v1/recipes/ingredient/{$base->id}", ['yield_qty' => '1000', 'lines' => [['ingredient_id' => $blend->id, 'qty' => '500']]], $this->headers)->assertOk();
        $this->putJson("/api/v1/recipes/ingredient/{$blend->id}", ['yield_qty' => '1000', 'lines' => [['ingredient_id' => $base->id, 'qty' => '100']]], $this->headers)
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'RECIPE_CYCLE');
        $this->putJson("/api/v1/recipes/ingredient/{$blend->id}", ['lines' => [['ingredient_id' => $blend->id, 'qty' => '1']]], $this->headers)
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'RECIPE_CYCLE');

        // Sub-resep hanya untuk bahan setengah jadi.
        $this->putJson("/api/v1/recipes/ingredient/{$this->milk->id}", ['lines' => [['ingredient_id' => $this->beans->id, 'qty' => '1']]], $this->headers)
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'NOT_SEMI_FINISHED');
        // Bahan setengah jadi yang dijabarkan tidak disimpan sebagai stok.
        $this->postJson('/api/v1/stock-adjustments', [
            'location_id' => Stock::location($this->pos->company, $this->pos->outlet)->id, 'type' => 'adjustment', 'reason_code' => 'opening',
            'lines' => [['ingredient_id' => $base->id, 'qty' => '100']],
        ], $this->headers)->assertUnprocessable()->assertJsonPath('errors.0.code', 'NOT_STOCKED');
    });

    it('manajer brand boleh mengubah resep menu brand-nya saja; manajer outlet hanya melihat', function () {
        [$brandManager] = Factory::staff($this->pos->company, ['brand_manager'], [], null, [$this->pos->outlet->brand_id]);
        $url = '/api/v1/recipes/item/'.$this->pos->coffee->id;
        $body = ['lines' => [['ingredient_id' => $this->beans->id, 'qty' => '18']]];
        $this->putJson($url, $body, asMember($brandManager, $this->pos->company))->assertOk();

        $manager = asMember($this->pos->staff['manager']['user'], $this->pos->company);
        $this->getJson($url, $manager)->assertOk();
        $this->putJson($url, $body, $manager)->assertForbidden();

        [$otherBrand] = Factory::staff($this->pos->company, ['brand_manager'], [], null, [Factory::brand($this->pos->company)->id]);
        $this->putJson($url, $body, asMember($otherBrand, $this->pos->company))->assertForbidden();
        $this->getJson($url, asMember($this->pos->staff['cashier']['user'], $this->pos->company))->assertForbidden();
    });

    it('isolasi tenant: resep menu company lain tidak dapat dibaca atau diubah', function () {
        [$other, $otherOwner] = Factory::company('Warung Bu Ratna');
        $h = asMember($otherOwner, $other);
        $this->getJson('/api/v1/recipes/item/'.$this->pos->coffee->id, $h)->assertNotFound();
        $this->putJson('/api/v1/recipes/item/'.$this->pos->coffee->id, ['lines' => []], $h)->assertNotFound();
        $this->getJson('/api/v1/recipes/item/bukan-uuid', $this->headers)->assertNotFound();

        // Bahan milik company lain tidak dapat dipakai dalam resep.
        $foreign = Stock::ingredient($other, 'Teh Melati', 'g');
        $this->putJson('/api/v1/recipes/item/'.$this->pos->coffee->id, ['lines' => [['ingredient_id' => $foreign->id, 'qty' => '5']]], $this->headers)
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'INGREDIENT_UNKNOWN');
    });
});
