<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Http\Resources\InventoryResources;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Shared\Support\Like;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Posisi stok & kartu stok (FR-INV-08, FR-INV-09). */
class StockController extends Controller
{
    public function __construct(private readonly InventoryAccess $access) {}

    public function balances(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'outlet_id' => ['nullable', 'uuid'],
            'location_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:60'],
            'below_minimum' => ['nullable', 'boolean'],
        ]);
        $locations = StockLocation::query()->whereIn('outlet_id', $this->access->outletIds($this->actor($request)))->pluck('id');

        $query = StockBalance::query()
            ->with('ingredient:id,code,name,category,base_unit,min_stock')
            ->join('ingredients', 'ingredients.id', '=', 'stock_balances.ingredient_id')
            ->select('stock_balances.*')
            ->whereIn('stock_balances.location_id', $locations)
            ->when($filters['outlet_id'] ?? null, fn ($q, $v) => $q->whereIn('stock_balances.location_id', StockLocation::query()->where('outlet_id', $v)->select('id')))
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('stock_balances.location_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('ingredients.name', 'ilike', Like::contains((string) $v))->orWhere('ingredients.code', 'ilike', Like::contains((string) $v))))
            ->when($request->boolean('below_minimum'), fn ($q) => $q->whereRaw('COALESCE(stock_balances.min_qty, ingredients.min_stock) > 0 AND stock_balances.qty < COALESCE(stock_balances.min_qty, ingredients.min_stock)'))
            ->orderBy('ingredients.name')
            ->orderBy('stock_balances.location_id');

        return ApiResponse::paginated($query->paginate($this->perPage($request)), InventoryResources::balance(...));
    }

    public function movements(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'location_id' => ['nullable', 'uuid'],
            'outlet_id' => ['nullable', 'uuid'],
            'ingredient_id' => ['nullable', 'uuid'],
            'type' => ['nullable', Rule::in(array_keys(StockMovement::TYPES))],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'reference_id' => ['nullable', 'uuid'],
        ]);
        $to = $filters['date_to'] ?? InventoryAccess::today();
        $from = $filters['date_from'] ?? CarbonImmutable::parse($to)->subDays(30)->format('Y-m-d');

        $query = StockMovement::query()
            ->whereIn('outlet_id', $this->access->outletIds($this->actor($request)))
            ->whereBetween('business_date', [$from, $to])
            ->when($filters['outlet_id'] ?? null, fn ($q, $v) => $q->where('outlet_id', $v))
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['ingredient_id'] ?? null, fn ($q, $v) => $q->where('ingredient_id', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['reference_id'] ?? null, fn ($q, $v) => $q->where('reference_id', $v))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        return ApiResponse::paginated($query->paginate($this->perPage($request)), InventoryResources::movement(...));
    }
}
