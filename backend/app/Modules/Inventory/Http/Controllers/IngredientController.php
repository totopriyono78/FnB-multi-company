<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Inventory\Application\InventoryException;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\IngredientUnit;
use App\Modules\Inventory\Domain\Models\RecipeLine;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Http\Requests\IngredientRequest;
use App\Modules\Inventory\Http\Resources\InventoryResources;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Shared\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Bahan baku & satuan (FR-INV-01). */
class IngredientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Ingredient::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:60'],
            'category' => ['nullable', 'string', 'max:40'],
            'kind' => ['nullable', Rule::in(array_keys(Ingredient::KINDS))],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $query = Ingredient::query()->with('units')
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'ilike', Like::contains((string) $v))->orWhere('code', 'ilike', Like::contains((string) $v))))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when($filters['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name');

        return ApiResponse::paginated($query->paginate($this->perPage($request)), InventoryResources::ingredient(...));
    }

    public function store(IngredientRequest $request): JsonResponse
    {
        $this->authorize('create', Ingredient::class);
        $data = $request->validated();

        $ingredient = DB::transaction(function () use ($data): Ingredient {
            $ingredient = Ingredient::query()->create(array_diff_key($data, ['units' => true]));
            $this->syncUnits($ingredient, $data['units'] ?? []);

            return $ingredient;
        });

        return ApiResponse::created(InventoryResources::ingredient($ingredient->refresh()->load(['units', 'recipe'])));
    }

    public function show(Ingredient $ingredient): JsonResponse
    {
        $this->authorize('view', $ingredient);

        return ApiResponse::ok(InventoryResources::ingredient($ingredient->load(['units', 'recipe'])));
    }

    public function update(IngredientRequest $request, Ingredient $ingredient, AuditLogger $audit): JsonResponse
    {
        $this->authorize('update', $ingredient);
        $data = $request->validated();

        DB::transaction(function () use ($ingredient, $data, $audit): void {
            $ingredient->update(array_diff_key($data, ['units' => true]));
            if (array_key_exists('units', $data)) {
                $before = $ingredient->units()->get()->map(fn (IngredientUnit $u) => $u->name.'='.$u->factor)->all();
                $this->syncUnits($ingredient, $data['units'], true);
                $after = $ingredient->units()->get()->map(fn (IngredientUnit $u) => $u->name.'='.$u->factor)->all();
                if ($before !== $after) {
                    $audit->log('ingredient.units_updated', $ingredient, ['units' => $before], ['units' => $after]);
                }
            }
        });

        return ApiResponse::ok(InventoryResources::ingredient($ingredient->refresh()->load(['units', 'recipe'])));
    }

    public function destroy(Ingredient $ingredient): JsonResponse
    {
        $this->authorize('delete', $ingredient);
        if (RecipeLine::query()->where('ingredient_id', $ingredient->id)->exists()) {
            throw new InventoryException('INGREDIENT_IN_USE', 'Bahan masih dipakai di resep. Hapus dari resep atau nonaktifkan bahan.');
        }
        if (StockBalance::query()->where('ingredient_id', $ingredient->id)->where('qty', '<>', 0)->exists()) {
            throw new InventoryException('INGREDIENT_HAS_STOCK', 'Bahan masih memiliki saldo stok. Nolkan lewat penyesuaian atau nonaktifkan bahan.');
        }
        $ingredient->delete();

        return ApiResponse::ok(['id' => $ingredient->id, 'deleted' => true]);
    }

    /** @param  list<array{name: string, factor: string|int|float, is_purchase_default?: bool}>  $units */
    private function syncUnits(Ingredient $ingredient, array $units, bool $replace = false): void
    {
        if ($replace) {
            // Satuan yang sudah dipakai PO/penerimaan tetap tercatat di dokumen (nama & faktor disalin).
            $ingredient->units()->delete();
        }
        $hasDefault = false;
        foreach ($units as $unit) {
            $isDefault = ! $hasDefault && (bool) ($unit['is_purchase_default'] ?? false);
            $hasDefault = $hasDefault || $isDefault;
            $ingredient->units()->create([
                'name' => trim($unit['name']),
                'factor' => (string) $unit['factor'],
                'is_purchase_default' => $isDefault,
            ]);
        }
    }
}
