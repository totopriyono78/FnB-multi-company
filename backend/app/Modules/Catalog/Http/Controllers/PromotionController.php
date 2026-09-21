<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\CatalogRemover;
use App\Modules\Catalog\Application\MenuScope;
use App\Modules\Catalog\Application\PromotionWriter;
use App\Modules\Catalog\Domain\Models\Promotion;
use App\Modules\Catalog\Http\Requests\PromotionRequest;
use App\Modules\Catalog\Http\Resources\CatalogResources;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Shared\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-MENU-11, FR-MENU-12 */
class PromotionController extends Controller
{
    public function __construct(
        private readonly MenuScope $scope,
        private readonly PromotionWriter $writer,
        private readonly CatalogRemover $remover,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Promotion::class);
        $brands = $this->scope->brandIds($this->actor($request));

        $promos = Promotion::query()->with(['targets', 'outlets'])
            ->when($brands !== null, fn ($q) => $q->where(fn ($q) => $q->whereIn('brand_id', $brands)->orWhereNull('brand_id')))
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->string('brand_id')))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', Like::contains((string) $request->string('search')))
                ->orWhere('code', 'ilike', Like::startsWith((string) $request->string('search')))))
            ->orderByDesc('starts_at')
            ->paginate($this->perPage($request));

        return ApiResponse::paginated($promos, CatalogResources::promotion(...));
    }

    public function store(PromotionRequest $request): JsonResponse
    {
        $this->authorize('create', Promotion::class);

        return ApiResponse::created(CatalogResources::promotion($this->save($request, new Promotion)));
    }

    public function show(Promotion $promotion): JsonResponse
    {
        $this->authorize('view', $promotion);

        return ApiResponse::ok(CatalogResources::promotion($promotion->load(['targets', 'outlets'])));
    }

    public function update(PromotionRequest $request, Promotion $promotion): JsonResponse
    {
        $this->authorize('update', $promotion);

        return ApiResponse::ok(CatalogResources::promotion($this->save($request, $promotion)));
    }

    public function destroy(Promotion $promotion): JsonResponse
    {
        $this->authorize('delete', $promotion);
        $this->remover->promotion($promotion);

        return ApiResponse::ok(['id' => $promotion->id, 'deleted' => true]);
    }

    private function save(PromotionRequest $request, Promotion $promotion): Promotion
    {
        return $this->writer->save($this->actor($request), $promotion, $request->validated());
    }
}
