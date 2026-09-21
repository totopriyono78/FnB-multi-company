<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\RecipeService;
use App\Modules\Inventory\Application\StockLocations;
use App\Modules\Inventory\Domain\Models\Recipe;
use App\Modules\Inventory\Http\Requests\RecipeRequest;
use App\Modules\Inventory\Http\Resources\InventoryResources;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Resep menu/varian/modifier & sub-resep bahan (FR-INV-03). */
class RecipeController extends Controller
{
    public function __construct(
        private readonly RecipeService $recipes,
        private readonly InventoryAccess $access,
    ) {}

    public function show(Request $request, string $type, string $id, StockLocations $locations): JsonResponse
    {
        $target = $this->recipes->target($type, $id);
        $user = $this->actor($request);
        if (! $this->recipes->canView($user, $target['brand_id'])) {
            throw new AuthorizationException('Anda tidak memiliki akses ke resep ini.');
        }
        $data = $request->validate(['outlet_id' => ['nullable', 'uuid']]);

        $recipe = Recipe::query()->with('lines.ingredient')->where('target_type', $type)->where('target_id', $id)->first();

        // HPP teoritis per 1 porsi / 1 satuan dasar (FR-INV-10).
        $cost = null;
        if ($recipe !== null) {
            $locationId = null;
            if (isset($data['outlet_id'])) {
                $outlet = Outlet::query()->findOrFail($data['outlet_id']);
                if (! $this->access->allowsOutlet($user, $outlet)) {
                    throw new AuthorizationException('Anda tidak memiliki akses ke outlet ini.');
                }
                $locationId = $locations->forSale($outlet, null)->id;
            }
            $cost = $this->recipes->cost($type, $id, $locationId);
        }

        return ApiResponse::ok(InventoryResources::recipe($type, $id, $target, $recipe, $cost));
    }

    public function update(RecipeRequest $request, string $type, string $id): JsonResponse
    {
        /** @var array{yield_qty?: string, notes?: string|null, lines: list<array{ingredient_id: string, qty: string}>} $data */
        $data = $request->validated();
        $recipe = $this->recipes->save($this->actor($request), $type, $id, $data);
        $recipe?->load('lines.ingredient');

        return ApiResponse::ok(InventoryResources::recipe($type, $id, $this->recipes->target($type, $id), $recipe));
    }
}
