<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Modules\Inventory\Application\InventoryException;
use App\Modules\Inventory\Http\Resources\InventoryResources;
use App\Modules\Purchasing\Domain\Models\PurchaseOrder;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Purchasing\Http\Requests\SupplierRequest;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Shared\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Data pemasok (FR-PUR). */
class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Supplier::class);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:60'], 'is_active' => ['nullable', 'boolean']]);
        $query = Supplier::query()
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'ilike', Like::contains((string) $v))->orWhere('code', 'ilike', Like::contains((string) $v))))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name');

        return ApiResponse::paginated($query->paginate($this->perPage($request)), InventoryResources::supplier(...));
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        $this->authorize('create', Supplier::class);

        return ApiResponse::created(InventoryResources::supplier(Supplier::query()->create($request->validated())->refresh()));
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $this->authorize('view', $supplier);

        return ApiResponse::ok(InventoryResources::supplier($supplier));
    }

    public function update(SupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $this->authorize('update', $supplier);
        $supplier->update($request->validated());

        return ApiResponse::ok(InventoryResources::supplier($supplier->refresh()));
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->authorize('delete', $supplier);
        $open = PurchaseOrder::query()->where('supplier_id', $supplier->id)
            ->whereIn('status', [PurchaseOrder::DRAFT, PurchaseOrder::SUBMITTED, PurchaseOrder::APPROVED, PurchaseOrder::PARTIALLY_RECEIVED])
            ->exists();
        if ($open) {
            throw new InventoryException('SUPPLIER_IN_USE', 'Pemasok masih memiliki purchase order yang berjalan.');
        }
        $supplier->delete();

        return ApiResponse::ok(['id' => $supplier->id, 'deleted' => true]);
    }
}
