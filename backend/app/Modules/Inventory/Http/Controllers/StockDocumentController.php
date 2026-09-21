<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\StockDocumentService;
use App\Modules\Inventory\Domain\Models\StockAdjustment;
use App\Modules\Inventory\Domain\Models\StockTransfer;
use App\Modules\Inventory\Http\Requests\StockAdjustmentRequest;
use App\Modules\Inventory\Http\Requests\StockTransferRequest;
use App\Modules\Inventory\Http\Resources\InventoryResources;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Penyesuaian, waste, dan transfer stok (FR-INV-05). */
class StockDocumentController extends Controller
{
    public function __construct(
        private readonly InventoryAccess $access,
        private readonly StockDocumentService $documents,
    ) {}

    public function adjustments(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'outlet_id' => ['nullable', 'uuid'],
            'type' => ['nullable', Rule::in(array_keys(StockAdjustment::TYPES))],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $to = $filters['date_to'] ?? InventoryAccess::today();
        $from = $filters['date_from'] ?? CarbonImmutable::parse($to)->subDays(30)->format('Y-m-d');
        $query = StockAdjustment::query()
            ->whereIn('outlet_id', $this->access->outletIds($this->actor($request)))
            ->whereBetween('business_date', [$from, $to])
            ->when($filters['outlet_id'] ?? null, fn ($q, $v) => $q->where('outlet_id', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->orderByDesc('occurred_at');

        return ApiResponse::paginated($query->paginate($this->perPage($request)), InventoryResources::adjustment(...));
    }

    public function storeAdjustment(StockAdjustmentRequest $request): JsonResponse
    {
        /** @var array{location_id: string, type: string, reason_code: string, notes?: string|null, occurred_at?: string|null, lines: list<array{ingredient_id: string, qty: string, unit_cost?: string|null, note?: string|null}>} $data */
        $data = $request->validated();

        return ApiResponse::created(InventoryResources::adjustment($this->documents->adjust($this->actor($request), $data)));
    }

    public function adjustment(Request $request, StockAdjustment $adjustment): JsonResponse
    {
        $this->visible($request, [$adjustment->outlet_id]);

        return ApiResponse::ok(InventoryResources::adjustment($adjustment->load('lines')));
    }

    public function transfers(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'outlet_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(array_keys(StockTransfer::STATUSES))],
            'direction' => ['nullable', Rule::in(['in', 'out'])],
        ]);
        $ids = $this->access->outletIds($this->actor($request));
        $outlet = $filters['outlet_id'] ?? null;
        $query = StockTransfer::query()
            ->where(fn ($q) => $q->whereIn('from_outlet_id', $ids)->orWhereIn('to_outlet_id', $ids))
            ->when($outlet !== null, fn ($q) => match ($filters['direction'] ?? null) {
                'in' => $q->where('to_outlet_id', $outlet),
                'out' => $q->where('from_outlet_id', $outlet),
                default => $q->where(fn ($w) => $w->where('from_outlet_id', $outlet)->orWhere('to_outlet_id', $outlet)),
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('sent_at');

        return ApiResponse::paginated($query->paginate($this->perPage($request)), InventoryResources::transfer(...));
    }

    public function storeTransfer(StockTransferRequest $request): JsonResponse
    {
        /** @var array{from_location_id: string, to_location_id: string, notes?: string|null, lines: list<array{ingredient_id: string, qty: string, note?: string|null}>} $data */
        $data = $request->validated();

        return ApiResponse::created(InventoryResources::transfer($this->documents->send($this->actor($request), $data)));
    }

    public function transfer(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->visible($request, [$transfer->from_outlet_id, $transfer->to_outlet_id]);

        return ApiResponse::ok(InventoryResources::transfer($transfer->load('lines')));
    }

    public function receiveTransfer(StockTransferRequest $request, StockTransfer $transfer): JsonResponse
    {
        $this->visible($request, [$transfer->from_outlet_id, $transfer->to_outlet_id]);
        /** @var array{note?: string|null, lines?: list<array{line_id: string, qty_received: string}>} $data */
        $data = $request->validated();

        return ApiResponse::ok(InventoryResources::transfer($this->documents->receive($this->actor($request), $transfer, $data)));
    }

    public function cancelTransfer(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->visible($request, [$transfer->from_outlet_id, $transfer->to_outlet_id]);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return ApiResponse::ok(InventoryResources::transfer($this->documents->cancelTransfer($this->actor($request), $transfer, $data['reason'])));
    }

    /**
     * Dokumen di luar cakupan outlet user diperlakukan tidak ada (404).
     *
     * @param  list<string>  $outletIds
     */
    private function visible(Request $request, array $outletIds): void
    {
        abort_if(array_intersect($outletIds, $this->access->outletIds($this->actor($request))) === [], 404);
    }
}
