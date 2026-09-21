<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Identity\Application\AccessScope;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Shared\Support\Like;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Http\Requests\BrandRequest;
use App\Modules\Tenancy\Http\Resources\BrandResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-TEN-04 */
class BrandController extends Controller
{
    public function __construct(private readonly AccessScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Brand::class);
        $user = $this->actor($request);
        $scope = $this->scope->for($user);

        $brands = Brand::query()
            ->withCount('outlets')
            ->when($scope !== null, function ($q) use ($scope): void {
                $q->where(function ($q) use ($scope): void {
                    $q->whereIn('id', $scope['brands'])
                        ->orWhereHas('outlets', fn ($o) => $o->whereIn('id', $scope['outlets']));
                });
            })
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request): void {
                $term = Like::contains((string) $request->string('search'));
                $q->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term);
            }))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return ApiResponse::ok(BrandResource::collection($brands));
    }

    public function store(BrandRequest $request): JsonResponse
    {
        $this->authorize('create', Brand::class);

        $brand = Brand::query()->create($request->validated());

        return ApiResponse::created(new BrandResource($brand));
    }

    public function show(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        return ApiResponse::ok(new BrandResource($brand->loadCount('outlets')));
    }

    public function update(BrandRequest $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $brand->update($request->validated());

        return ApiResponse::ok(new BrandResource($brand));
    }

    public function destroy(Brand $brand): JsonResponse
    {
        $this->authorize('delete', $brand);

        if ($brand->outlets()->where('is_active', true)->exists()) {
            return ApiResponse::error(409, [[
                'code' => 'BRAND_HAS_ACTIVE_OUTLETS',
                'message' => 'Brand masih punya outlet aktif. Nonaktifkan outlet terlebih dahulu.',
            ]]);
        }

        $brand->update(['is_active' => false]);
        $brand->delete();

        return ApiResponse::ok(['id' => $brand->id, 'deleted' => true]);
    }
}
