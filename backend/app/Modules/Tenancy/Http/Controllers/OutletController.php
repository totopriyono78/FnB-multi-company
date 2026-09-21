<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Identity\Application\AccessScope;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Shared\Support\Like;
use App\Modules\Tenancy\Application\PlanLimits;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;
use App\Modules\Tenancy\Http\Requests\OutletRequest;
use App\Modules\Tenancy\Http\Resources\OutletResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-TEN-05 */
class OutletController extends Controller
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly PlanLimits $limits,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Outlet::class);

        $query = Outlet::query()->with('brand')
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->string('brand_id')))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request): void {
                $term = Like::contains((string) $request->string('search'));
                $q->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term)->orWhere('city', 'ilike', $term);
            }))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name');

        $this->scope->applyToOutletQuery($query, $this->actor($request));

        return ApiResponse::ok(OutletResource::collection($query->paginate($this->perPage($request))));
    }

    public function store(OutletRequest $request): JsonResponse
    {
        $brand = Brand::query()->findOrFail($request->string('brand_id'));
        $this->authorize('create', [Outlet::class, $brand]);
        $this->limits->ensureCanAddOutlet();

        $outlet = Outlet::query()->create($request->validated());

        return ApiResponse::created(new OutletResource($outlet->refresh()->load('brand')));
    }

    public function show(Outlet $outlet): JsonResponse
    {
        $this->authorize('view', $outlet);

        return ApiResponse::ok(new OutletResource($outlet->load('brand')));
    }

    public function update(OutletRequest $request, Outlet $outlet): JsonResponse
    {
        $this->authorize('update', $outlet);

        if ($request->filled('brand_id') && $request->string('brand_id')->toString() !== $outlet->brand_id) {
            $this->authorize('create', [Outlet::class, Brand::query()->findOrFail($request->string('brand_id'))]);
        }

        $outlet->update($request->validated());

        return ApiResponse::ok(new OutletResource($outlet->load('brand')));
    }

    public function destroy(Outlet $outlet): JsonResponse
    {
        $this->authorize('delete', $outlet);

        $outlet->update(['is_active' => false]);
        $outlet->delete();

        return ApiResponse::ok(['id' => $outlet->id, 'deleted' => true]);
    }
}
