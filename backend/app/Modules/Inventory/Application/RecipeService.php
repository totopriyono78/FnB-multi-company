<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Catalog\Application\MenuScope;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemVariant;
use App\Modules\Catalog\Domain\Models\Modifier;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\Recipe;
use App\Modules\Inventory\Domain\Models\RecipeLine;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Tenancy\Application\WritableCompany;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menyimpan resep menu/varian/modifier dan sub-resep bahan setengah jadi (FR-INV-03).
 * Resep yang berubah tidak mengubah pemakaian bahan transaksi yang sudah diposting.
 */
class RecipeService
{
    public function __construct(
        private readonly InventoryAccess $access,
        private readonly MenuScope $menuScope,
        private readonly RecipeExplorer $explorer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Data target resep: [brand_id, label, base_unit|null].
     *
     * @return array{brand_id: string|null, label: string, base_unit: string|null}
     */
    public function target(string $type, string $id): array
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(Recipe::class, [$id]);
        }

        return match ($type) {
            Recipe::ITEM => (function () use ($id): array {
                $item = Item::query()->findOrFail($id);

                return ['brand_id' => $item->brand_id, 'label' => $item->name, 'base_unit' => null];
            })(),
            Recipe::VARIANT => (function () use ($id): array {
                $variant = ItemVariant::query()->with('item')->findOrFail($id);

                return ['brand_id' => $variant->item->brand_id, 'label' => $variant->item->name.' · '.$variant->name, 'base_unit' => null];
            })(),
            Recipe::MODIFIER => (function () use ($id): array {
                $modifier = Modifier::query()->with('group')->findOrFail($id);

                return ['brand_id' => $modifier->group->brand_id, 'label' => $modifier->group->name.' · '.$modifier->name, 'base_unit' => null];
            })(),
            Recipe::INGREDIENT => (function () use ($id): array {
                $ingredient = Ingredient::query()->findOrFail($id);
                if (! $ingredient->isSemi()) {
                    throw new InventoryException('NOT_SEMI_FINISHED', 'Sub-resep hanya untuk bahan setengah jadi.', 422, 'target_id');
                }

                return ['brand_id' => null, 'label' => $ingredient->name, 'base_unit' => $ingredient->base_unit];
            })(),
            default => throw (new ModelNotFoundException)->setModel(Recipe::class, [$id]),
        };
    }

    public function canView(User $user, ?string $brandId): bool
    {
        if ($this->access->canView($user) && ($brandId === null || $this->menuScope->allowsBrand($user, $brandId))) {
            return true;
        }

        return $brandId !== null && ($user->can('menu.view') || $user->can('menu.manage')) && $this->menuScope->allowsBrand($user, $brandId);
    }

    public function canManage(User $user, ?string $brandId): bool
    {
        if (! WritableCompany::allows()) {
            return false;
        }
        if ($this->access->canManageMaster($user)) {
            return true;
        }

        return $brandId !== null && $this->menuScope->canManageBrand($user, $brandId);
    }

    /**
     * @param  array{yield_qty?: string|int|float|null, notes?: string|null, lines: list<array{ingredient_id: string, qty: string|int|float}>}  $data
     */
    public function save(User $actor, string $type, string $id, array $data): ?Recipe
    {
        $target = $this->target($type, $id);
        if (! $this->canManage($actor, $target['brand_id'])) {
            throw new AuthorizationException('Anda tidak memiliki izin mengubah resep ini.');
        }

        $lines = $data['lines'];
        $ingredientIds = array_column($lines, 'ingredient_id');
        $ingredients = Ingredient::query()->whereIn('id', $ingredientIds)->get()->keyBy('id');
        foreach ($lines as $i => $line) {
            /** @var Ingredient|null $ingredient */
            $ingredient = $ingredients->get($line['ingredient_id']);
            if ($ingredient === null) {
                throw new InventoryException('INGREDIENT_UNKNOWN', 'Bahan tidak ditemukan.', 422, "lines.{$i}.ingredient_id");
            }
            if (! $ingredient->is_active) {
                throw new InventoryException('INGREDIENT_INACTIVE', "Bahan {$ingredient->name} nonaktif.", 422, "lines.{$i}.ingredient_id");
            }
            $qty = BigDecimal::of((string) $line['qty']);
            if ($qty->isZero() || ($qty->isNegative() && $type !== Recipe::MODIFIER)) {
                throw new InventoryException('INVALID_QTY', 'Jumlah bahan harus lebih dari 0 (nilai minus hanya untuk modifier, mis. "tanpa gula").', 422, "lines.{$i}.qty");
            }
            if ($type === Recipe::INGREDIENT && $line['ingredient_id'] === $id) {
                throw new InventoryException('RECIPE_CYCLE', 'Bahan tidak boleh memakai dirinya sendiri.', 422, "lines.{$i}.ingredient_id");
            }
        }
        if ($type === Recipe::INGREDIENT) {
            $this->explorer->flush();
            if ($this->explorer->createsCycle($id, $ingredientIds)) {
                throw new InventoryException('RECIPE_CYCLE', 'Sub-resep saling merujuk. Periksa bahan setengah jadi yang dipakai.', 422, 'lines');
            }
        }

        return DB::transaction(function () use ($actor, $type, $id, $data, $lines, $target): ?Recipe {
            /** @var Recipe|null $recipe */
            $recipe = Recipe::query()->with('lines')->where('target_type', $type)->where('target_id', $id)->lockForUpdate()->first();
            $old = $recipe === null ? null : $this->snapshot($recipe);

            if ($lines === []) {
                if ($recipe !== null) {
                    $recipe->lines()->delete();
                    $recipe->delete();
                    $this->audit->log('recipe.deleted', $recipe, old: $old, userId: $actor->id, metadata: ['target_type' => $type, 'target_id' => $id, 'label' => $target['label']]);
                }
                $this->explorer->flush();

                return null;
            }

            $recipe ??= new Recipe;
            $recipe->forceFill([
                'target_type' => $type,
                'target_id' => $id,
                'brand_id' => $target['brand_id'],
                'yield_qty' => (string) BigDecimal::of((string) ($data['yield_qty'] ?? '1')),
                'notes' => $data['notes'] ?? null,
            ]);
            $recipe->updated_at = now()->toImmutable();
            $recipe->save();

            RecipeLine::query()->where('recipe_id', $recipe->id)->delete();
            $rows = [];
            foreach (array_values($lines) as $n => $line) {
                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'company_id' => $recipe->company_id,
                    'recipe_id' => $recipe->id,
                    'ingredient_id' => $line['ingredient_id'],
                    'qty' => (string) BigDecimal::of((string) $line['qty']),
                    'sort_order' => $n,
                ];
            }
            RecipeLine::query()->insert($rows);
            $recipe->load('lines');

            $new = $this->snapshot($recipe);
            if ($old != $new) {
                $this->audit->log($old === null ? 'recipe.created' : 'recipe.updated', $recipe, old: $old, new: $new, userId: $actor->id, metadata: ['target_type' => $type, 'target_id' => $id, 'label' => $target['label']]);
            }
            $this->explorer->flush();

            return $recipe;
        });
    }

    /**
     * HPP teoritis 1 porsi (atau 1 satuan dasar untuk sub-resep): HPP rata-rata lokasi bila ada,
     * selain itu harga beli terakhir bahan.
     *
     * @return array{total: string, missing_cost: bool, basis: string, ingredients: list<array<string, mixed>>}
     */
    public function cost(string $type, string $id, ?string $locationId): array
    {
        $usage = $this->explorer->forTarget($type, $id);
        $avg = $locationId === null
            ? collect()
            : StockBalance::query()->where('location_id', $locationId)->whereIn('ingredient_id', array_keys($usage))->pluck('avg_cost', 'ingredient_id');
        $info = Ingredient::withTrashed()->whereIn('id', array_keys($usage))->get(['id', 'name', 'base_unit', 'last_cost'])->keyBy('id');
        $total = BigDecimal::zero();
        $parts = [];
        $missing = false;
        foreach ($usage as $ingredientId => $qty) {
            $unit = $avg->get($ingredientId);
            $unit = $unit !== null && BigDecimal::of((string) $unit)->isPositive()
                ? BigDecimal::of((string) $unit)
                : BigDecimal::of((string) ($info->get($ingredientId)->last_cost ?? '0'));
            $missing = $missing || $unit->isZero();
            $value = $qty->multipliedBy($unit)->toScale(2, RoundingMode::HALF_UP);
            $total = $total->plus($value);
            $parts[] = [
                'ingredient_id' => $ingredientId,
                'name' => $info->get($ingredientId)?->name,
                'base_unit' => $info->get($ingredientId)?->base_unit,
                'qty' => (string) $qty->toScale(4, RoundingMode::HALF_UP),
                'unit_cost' => (string) $unit->toScale(6),
                'value' => (string) $value,
            ];
        }

        return ['total' => (string) $total->toScale(2), 'missing_cost' => $missing, 'basis' => $locationId === null ? 'last_purchase' : 'outlet_average', 'ingredients' => $parts];
    }

    /** @return array<string, mixed> */
    private function snapshot(Recipe $recipe): array
    {
        return [
            'yield_qty' => (string) BigDecimal::of((string) $recipe->yield_qty)->strippedOfTrailingZeros(),
            'notes' => $recipe->notes,
            'lines' => $recipe->lines->map(fn (RecipeLine $l) => [
                'ingredient_id' => $l->ingredient_id,
                'qty' => (string) BigDecimal::of((string) $l->qty)->strippedOfTrailingZeros(),
            ])->values()->all(),
        ];
    }
}
