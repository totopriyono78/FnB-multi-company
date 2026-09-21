<?php

namespace Tests\Support;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Application\RecipeService;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Application\StockLocations;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\Recipe;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Data inventory untuk pengujian (bahan, resep, saldo awal). */
final class Stock
{
    /** @param array<string, mixed> $attrs */
    public static function ingredient(Company $company, string $name, string $unit = 'g', array $attrs = [], array $units = []): Ingredient
    {
        return Factory::tenant($company, function () use ($name, $unit, $attrs, $units): Ingredient {
            $ingredient = Ingredient::query()->create($attrs + [
                'code' => strtoupper(Str::random(6)),
                'name' => $name,
                'base_unit' => $unit,
            ]);
            foreach ($units as $unitName => $factor) {
                $ingredient->units()->create(['name' => $unitName, 'factor' => (string) $factor]);
            }

            return $ingredient->refresh();
        });
    }

    /** @param list<array{0: Ingredient, 1: string|int|float}> $lines */
    public static function recipe(Company $company, string $type, string $targetId, array $lines, string $yield = '1'): ?Recipe
    {
        $owner = Factory::ownerOf($company);

        return Factory::tenant($company, fn () => app(RecipeService::class)->save($owner, $type, $targetId, [
            'yield_qty' => $yield,
            'lines' => array_map(fn (array $l) => ['ingredient_id' => $l[0]->id, 'qty' => (string) $l[1]], $lines),
        ]));
    }

    public static function location(Company $company, Outlet $outlet): StockLocation
    {
        return Factory::tenant($company, fn () => app(StockLocations::class)->ensureDefault($outlet));
    }

    /** Saldo awal lewat ledger (tipe penerimaan). */
    public static function receive(Company $company, StockLocation $location, Ingredient $ingredient, string $qty, string $unitCost, ?User $by = null): void
    {
        Factory::tenant($company, function () use ($location, $ingredient, $qty, $unitCost, $by): void {
            DB::transaction(fn () => app(StockLedger::class)->post([[
                'location' => $location,
                'ingredient_id' => $ingredient->id,
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'type' => 'receipt',
                'reference_type' => 'test',
                'reference_id' => (string) Str::uuid7(),
                'business_date' => now()->format('Y-m-d'),
                'occurred_at' => now()->toImmutable(),
                'created_by' => $by?->id,
            ]]));
        });
    }

    /** @return array{qty: string, avg_cost: string} */
    public static function balance(Company $company, StockLocation $location, Ingredient $ingredient): array
    {
        $row = Factory::tenant($company, fn () => StockBalance::query()->where('location_id', $location->id)->where('ingredient_id', $ingredient->id)->first());

        return ['qty' => (string) ($row->qty ?? '0.0000'), 'avg_cost' => (string) ($row->avg_cost ?? '0.000000')];
    }
}
