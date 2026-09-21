<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\StockLocations;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Inventory\Http\Requests\StockLocationRequest;
use App\Modules\Inventory\Http\Resources\InventoryResources;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Gudang / lokasi stok per outlet (FR-INV-02). */
class StockLocationController extends Controller
{
    public function __construct(
        private readonly InventoryAccess $access,
        private readonly StockLocations $locations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockLocation::class);
        $filters = $request->validate(['outlet_id' => ['nullable', 'uuid']]);
        $ids = $this->access->outletIds($this->actor($request), ! $this->access->canView($this->actor($request)));
        $rows = StockLocation::query()
            ->whereIn('outlet_id', $ids)
            ->when($filters['outlet_id'] ?? null, fn ($q, $v) => $q->where('outlet_id', $v))
            ->orderBy('outlet_id')->orderByDesc('is_default')->orderBy('name')
            ->get();

        return ApiResponse::ok($rows->map(fn (StockLocation $l) => InventoryResources::location($l))->values());
    }

    public function store(StockLocationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $outlet = Outlet::query()->findOrFail($data['outlet_id']);
        if (! $this->access->canManageOutlet($this->actor($request), $outlet)) {
            throw new AuthorizationException('Anda tidak memiliki izin mengelola lokasi stok outlet ini.');
        }

        $location = $this->locations->save(new StockLocation(['outlet_id' => $outlet->id]), $data);

        return ApiResponse::created(InventoryResources::location($location));
    }

    public function update(StockLocationRequest $request, StockLocation $location): JsonResponse
    {
        $this->authorize('update', $location);

        return ApiResponse::ok(InventoryResources::location($this->locations->save($location, $request->validated())));
    }
}
