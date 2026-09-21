<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\StockCountService;
use App\Modules\Inventory\Domain\Models\StockCount;
use App\Modules\Inventory\Http\Requests\StockCountRequest;
use App\Modules\Inventory\Http\Resources\InventoryResources;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Stock opname (FR-INV-06). */
class StockCountController extends Controller
{
    public function __construct(
        private readonly InventoryAccess $access,
        private readonly StockCountService $counts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'outlet_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(array_keys(StockCount::STATUSES))],
        ]);
        $query = StockCount::query()
            ->whereIn('outlet_id', $this->access->outletIds($this->actor($request)))
            ->when($filters['outlet_id'] ?? null, fn ($q, $v) => $q->where('outlet_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('started_at');

        return ApiResponse::paginated($query->paginate($this->perPage($request)), InventoryResources::count(...));
    }

    public function store(StockCountRequest $request): JsonResponse
    {
        /** @var array{location_id: string, scope: string, ingredient_ids?: list<string>|null, notes?: string|null} $data */
        $data = $request->validated();

        return ApiResponse::created(InventoryResources::count($this->counts->start($this->actor($request), $data)));
    }

    public function show(Request $request, StockCount $count): JsonResponse
    {
        $this->visible($request, $count);

        return ApiResponse::ok(InventoryResources::count($count->load('lines')));
    }

    public function record(StockCountRequest $request, StockCount $count): JsonResponse
    {
        $this->visible($request, $count);
        /** @var list<array{ingredient_id: string, counted_qty: string|null, note?: string|null}> $lines */
        $lines = $request->validated('lines');

        return ApiResponse::ok(InventoryResources::count($this->counts->record($this->actor($request), $count, $lines)));
    }

    public function submit(Request $request, StockCount $count): JsonResponse
    {
        $this->visible($request, $count);

        return ApiResponse::ok(InventoryResources::count($this->counts->submit($this->actor($request), $count)));
    }

    public function approve(Request $request, StockCount $count): JsonResponse
    {
        $this->visible($request, $count);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        return ApiResponse::ok(InventoryResources::count($this->counts->approve($this->actor($request), $count, $data['note'] ?? null)));
    }

    public function recount(Request $request, StockCount $count): JsonResponse
    {
        $this->visible($request, $count);
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:300']]);

        return ApiResponse::ok(InventoryResources::count($this->counts->requestRecount($this->actor($request), $count, $data['note'])));
    }

    public function cancel(Request $request, StockCount $count): JsonResponse
    {
        $this->visible($request, $count);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return ApiResponse::ok(InventoryResources::count($this->counts->cancel($this->actor($request), $count, $data['reason'])));
    }

    private function visible(Request $request, StockCount $count): void
    {
        abort_unless(in_array($count->outlet_id, $this->access->outletIds($this->actor($request)), true), 404);
    }
}
