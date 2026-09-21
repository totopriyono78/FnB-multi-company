<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\CatalogRemover;
use App\Modules\Catalog\Application\MenuScope;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Http\Requests\MenuCategoryRequest;
use App\Modules\Catalog\Http\Resources\CatalogResources;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Shared\Support\Like;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-MENU-01 */
class MenuCategoryController extends Controller
{
    public function __construct(private readonly MenuScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MenuCategory::class);

        $query = MenuCategory::query()->withCount('items')
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->string('brand_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', Like::contains((string) $request->string('search'))))
            ->orderBy('brand_id')->orderBy('sort_order')->orderBy('name');
        $this->scope->apply($query, $this->actor($request));

        return ApiResponse::paginated($query->paginate($this->perPage($request)), CatalogResources::category(...));
    }

    public function store(MenuCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', MenuCategory::class);
        $this->ensureManages($request, (string) $request->string('brand_id'));

        return ApiResponse::created(CatalogResources::category(MenuCategory::query()->create($request->validated())));
    }

    public function show(MenuCategory $category): JsonResponse
    {
        $this->authorize('view', $category);

        return ApiResponse::ok(CatalogResources::category($category->loadCount('items')));
    }

    public function update(MenuCategoryRequest $request, MenuCategory $category): JsonResponse
    {
        $this->authorize('update', $category);
        $category->update($request->validated());

        return ApiResponse::ok(CatalogResources::category($category));
    }

    public function destroy(MenuCategory $category): JsonResponse
    {
        $this->authorize('delete', $category);

        app(CatalogRemover::class)->category($category);

        return ApiResponse::ok(['id' => $category->id, 'deleted' => true]);
    }

    private function ensureManages(Request $request, string $brandId): void
    {
        if (! $this->scope->canManageBrand($this->actor($request), $brandId)) {
            throw new AuthorizationException('Anda tidak mengelola menu brand ini.');
        }
    }
}
