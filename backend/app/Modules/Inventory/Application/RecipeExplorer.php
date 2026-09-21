<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\Recipe;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Menjabarkan baris penjualan menjadi pemakaian bahan (FR-INV-03, FR-INV-04, ADR 0005).
 *
 * Aturan:
 * 1. Resep varian menggantikan resep menu; bila varian tidak punya resep, dipakai resep menu.
 * 2. Resep modifier ditambahkan × jumlah modifier (boleh negatif, mis. "tanpa gula").
 * 3. Paket: resep paket (bila ada) + resep setiap pilihan paket.
 * 4. Bahan setengah jadi yang punya resep dijabarkan ke bahan penyusunnya (qty / hasil resep), maks. 5 tingkat.
 *    Bahan setengah jadi tanpa resep diperlakukan sebagai barang stok biasa.
 * 5. Total per bahan yang ≤ 0 diabaikan.
 */
class RecipeExplorer
{
    public const MAX_DEPTH = 5;

    /** @var array<string, Recipe|false> */
    private array $recipes = [];

    /** @var array<string, string> ingredient_id => kind */
    private array $kinds = [];

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function prepare(array $lines): void
    {
        $wanted = [];
        foreach ($lines as $line) {
            $wanted[Recipe::ITEM][] = $line['item_id'];
            if (! empty($line['variant_id'])) {
                $wanted[Recipe::VARIANT][] = $line['variant_id'];
            }
            foreach ($line['modifiers'] ?? [] as $m) {
                $wanted[Recipe::MODIFIER][] = $m['id'];
            }
            foreach ($line['bundle'] ?? [] as $b) {
                $wanted[Recipe::ITEM][] = $b['item_id'];
                if (! empty($b['variant_id'])) {
                    $wanted[Recipe::VARIANT][] = $b['variant_id'];
                }
            }
        }
        foreach ($wanted as $type => $ids) {
            $this->load($type, $ids);
        }
    }

    /**
     * Pemakaian bahan untuk satu baris (sudah dikali qty baris).
     *
     * @param  array<string, mixed>  $line  {item_id, variant_id, qty, modifiers:[{id, qty}], bundle:[{item_id, variant_id}]}
     * @return array<string, BigDecimal> ingredient_id => qty satuan dasar
     */
    public function forLine(array $line): array
    {
        $this->prepare([$line]);
        $perUnit = [];
        $this->addMenu($perUnit, (string) $line['item_id'], $line['variant_id'] ?? null, BigDecimal::one());
        foreach ($line['modifiers'] ?? [] as $m) {
            $this->addRecipe($perUnit, $this->recipe(Recipe::MODIFIER, (string) $m['id']), BigDecimal::of((int) $m['qty']));
        }
        foreach ($line['bundle'] ?? [] as $b) {
            $this->addMenu($perUnit, (string) $b['item_id'], $b['variant_id'] ?? null, BigDecimal::one());
        }

        $qty = BigDecimal::of((string) $line['qty']);
        $result = [];
        foreach ($this->explode($perUnit) as $ingredientId => $amount) {
            $total = $amount->multipliedBy($qty)->toScale(StockLedger::QTY_SCALE, RoundingMode::HALF_UP);
            if ($total->isPositive()) {
                $result[$ingredientId] = $total;
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * Pemakaian bahan untuk 1 unit target resep (untuk HPP teoritis).
     *
     * @return array<string, BigDecimal>
     */
    public function forTarget(string $type, string $id): array
    {
        $recipe = $this->recipe($type, $id);
        $perUnit = [];
        if ($recipe !== null) {
            $divisor = $type === Recipe::INGREDIENT ? (string) $recipe->yield_qty : '1';
            foreach ($recipe->lines as $line) {
                $perUnit[$line->ingredient_id] = ($perUnit[$line->ingredient_id] ?? BigDecimal::zero())
                    ->plus(BigDecimal::of((string) $line->qty)->dividedBy($divisor, 10, RoundingMode::HALF_UP));
            }
        }

        return array_filter($this->explode($perUnit), fn (BigDecimal $q) => $q->isPositive());
    }

    /**
     * Apakah bahan setengah jadi `$ingredientId` akan membentuk siklus bila resepnya memakai `$components`.
     *
     * @param  list<string>  $components
     */
    public function createsCycle(string $ingredientId, array $components): bool
    {
        $queue = $components;
        $seen = [];
        $steps = 0;
        while ($queue !== [] && $steps < 500) {
            $steps++;
            $current = array_shift($queue);
            if ($current === $ingredientId) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            $recipe = $this->recipe(Recipe::INGREDIENT, $current, true);
            if ($recipe !== null) {
                foreach ($recipe->lines as $line) {
                    $queue[] = $line->ingredient_id;
                }
            }
        }

        return false;
    }

    /** Hapus cache (dipanggil setelah resep diubah). */
    public function flush(): void
    {
        $this->recipes = [];
        $this->kinds = [];
    }

    /** @param  array<string, BigDecimal>  $acc */
    private function addMenu(array &$acc, string $itemId, ?string $variantId, BigDecimal $factor): void
    {
        $recipe = $variantId !== null ? $this->recipe(Recipe::VARIANT, $variantId) : null;
        $recipe ??= $this->recipe(Recipe::ITEM, $itemId);
        $this->addRecipe($acc, $recipe, $factor);
    }

    /** @param  array<string, BigDecimal>  $acc */
    private function addRecipe(array &$acc, ?Recipe $recipe, BigDecimal $factor): void
    {
        if ($recipe === null) {
            return;
        }
        foreach ($recipe->lines as $line) {
            $acc[$line->ingredient_id] = ($acc[$line->ingredient_id] ?? BigDecimal::zero())
                ->plus(BigDecimal::of((string) $line->qty)->multipliedBy($factor));
        }
    }

    /**
     * Jabarkan bahan setengah jadi.
     *
     * @param  array<string, BigDecimal>  $amounts
     * @return array<string, BigDecimal>
     */
    private function explode(array $amounts, int $depth = 0): array
    {
        if ($amounts === []) {
            return [];
        }
        $this->loadKinds(array_keys($amounts));
        $semi = array_values(array_filter(array_keys($amounts), fn (string $id) => ($this->kinds[$id] ?? Ingredient::RAW) === Ingredient::SEMI));
        $this->load(Recipe::INGREDIENT, $semi);
        $explodable = array_values(array_filter($semi, fn (string $id) => ($this->recipe(Recipe::INGREDIENT, $id)?->lines->isNotEmpty()) ?? false));
        if ($explodable === []) {
            return $amounts;
        }
        if ($depth >= self::MAX_DEPTH) {
            throw new InventoryException('RECIPE_TOO_DEEP', 'Sub-resep terlalu bertingkat atau saling merujuk (maksimal '.self::MAX_DEPTH.' tingkat).', 422);
        }

        $next = [];
        foreach ($amounts as $ingredientId => $qty) {
            if (! in_array($ingredientId, $explodable, true)) {
                $next[$ingredientId] = ($next[$ingredientId] ?? BigDecimal::zero())->plus($qty);

                continue;
            }
            /** @var Recipe $recipe */
            $recipe = $this->recipe(Recipe::INGREDIENT, $ingredientId);
            $ratio = $qty->dividedBy((string) $recipe->yield_qty, 10, RoundingMode::HALF_UP);
            foreach ($recipe->lines as $line) {
                $next[$line->ingredient_id] = ($next[$line->ingredient_id] ?? BigDecimal::zero())
                    ->plus(BigDecimal::of((string) $line->qty)->multipliedBy($ratio));
            }
        }

        return $this->explode($next, $depth + 1);
    }

    /** @param  list<string>  $ids */
    private function load(string $type, array $ids): void
    {
        $missing = [];
        foreach (array_unique($ids) as $id) {
            if (! array_key_exists($type.':'.$id, $this->recipes)) {
                $missing[] = $id;
            }
        }
        if ($missing === []) {
            return;
        }
        $found = Recipe::query()->with('lines')->where('target_type', $type)->whereIn('target_id', $missing)->get()->keyBy('target_id');
        foreach ($missing as $id) {
            $this->recipes[$type.':'.$id] = $found->get($id) ?? false;
        }
    }

    /** @param  list<string>  $ids */
    private function loadKinds(array $ids): void
    {
        $missing = array_values(array_filter($ids, fn (string $id) => ! isset($this->kinds[$id])));
        if ($missing === []) {
            return;
        }
        foreach (Ingredient::withTrashed()->whereIn('id', $missing)->pluck('kind', 'id') as $id => $kind) {
            $this->kinds[(string) $id] = (string) $kind;
        }
        foreach ($missing as $id) {
            $this->kinds[$id] ??= Ingredient::RAW;
        }
    }

    private function recipe(string $type, string $id, bool $load = false): ?Recipe
    {
        if ($load || ! array_key_exists($type.':'.$id, $this->recipes)) {
            $this->load($type, [$id]);
        }
        $recipe = $this->recipes[$type.':'.$id];

        return $recipe === false ? null : $recipe;
    }
}
