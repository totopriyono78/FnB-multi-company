<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\CatalogRemover;
use App\Modules\Catalog\Application\MenuScope;
use App\Modules\Catalog\Application\ModifierGroupWriter;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use App\Modules\Catalog\Http\Requests\ModifierGroupRequest;
use App\Modules\Catalog\Http\Resources\CatalogResources;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-MENU-04 */
class ModifierGroupController extends Controller
{
    public function __construct(
        private readonly MenuScope $scope,
        private readonly ModifierGroupWriter $writer,
        private readonly CatalogRemover $remover,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ModifierGroup::class);

        $query = ModifierGroup::query()->with('modifiers')
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->string('brand_id')))
            ->orderBy('sort_order')->orderBy('name');
        $this->scope->apply($query, $this->actor($request));

        return ApiResponse::paginated($query->paginate($this->perPage($request)), CatalogResources::modifierGroup(...));
    }

    public function store(ModifierGroupRequest $request): JsonResponse
    {
        $this->authorize('create', ModifierGroup::class);
        if (! $this->scope->canManageBrand($this->actor($request), (string) $request->string('brand_id'))) {
            throw new AuthorizationException('Anda tidak mengelola menu brand ini.');
        }

        $group = $this->writer->save(new ModifierGroup, $request->validated());

        return ApiResponse::created(CatalogResources::modifierGroup($group));
    }

    public function show(ModifierGroup $modifierGroup): JsonResponse
    {
        $this->authorize('view', $modifierGroup);

        return ApiResponse::ok(CatalogResources::modifierGroup($modifierGroup->load('modifiers')));
    }

    public function update(ModifierGroupRequest $request, ModifierGroup $modifierGroup): JsonResponse
    {
        $this->authorize('update', $modifierGroup);

        return ApiResponse::ok(CatalogResources::modifierGroup($this->writer->save($modifierGroup, $request->validated())));
    }

    public function destroy(ModifierGroup $modifierGroup): JsonResponse
    {
        $this->authorize('delete', $modifierGroup);

        $this->remover->modifierGroup($modifierGroup);

        return ApiResponse::ok(['id' => $modifierGroup->id, 'deleted' => true]);
    }
}
