<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Http\Resources\InventoryResources;
use App\Modules\Purchasing\Application\GoodsReceiptService;
use App\Modules\Purchasing\Application\PurchaseOrderService;
use App\Modules\Purchasing\Domain\Models\GoodsReceipt;
use App\Modules\Purchasing\Domain\Models\PurchaseOrder;
use App\Modules\Purchasing\Http\Requests\GoodsReceiptRequest;
use App\Modules\Purchasing\Http\Requests\PurchaseOrderRequest;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Shared\Support\Like;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Purchase order & penerimaan barang (FR-PUR, FR-INV-05). */
class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly InventoryAccess $access,
        private readonly PurchaseOrderService $orders,
        private readonly GoodsReceiptService $receipts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'outlet_id' => ['nullable', 'uuid'],
            'supplier_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(array_keys(PurchaseOrder::STATUSES))],
            'search' => ['nullable', 'string', 'max:40'],
        ]);
        $query = PurchaseOrder::query()->with('supplier:id,name')
            ->whereIn('outlet_id', $this->access->outletIds($this->actor($request), true))
            ->when($filters['outlet_id'] ?? null, fn ($q, $v) => $q->where('outlet_id', $v))
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('number', 'ilike', Like::contains((string) $v)))
            ->orderByDesc('created_at');

        return ApiResponse::paginated($query->paginate($this->perPage($request)), InventoryResources::purchaseOrder(...));
    }

    public function store(PurchaseOrderRequest $request): JsonResponse
    {
        /** @var array{location_id: string, supplier_id: string, order_date?: string|null, expected_date?: string|null, notes?: string|null, lines: list<array{ingredient_id: string, unit_name: string, qty: string, unit_price: string}>} $data */
        $data = $request->validated();

        return ApiResponse::created($this->present($this->orders->create($this->actor($request), $data)));
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->visible($request, $purchaseOrder);

        return ApiResponse::ok($this->present($purchaseOrder) + [
            'receipts' => GoodsReceipt::query()->where('purchase_order_id', $purchaseOrder->id)->orderBy('received_at')->get()
                ->map(fn (GoodsReceipt $g) => InventoryResources::receipt($g))->values()->all(),
        ]);
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->visible($request, $purchaseOrder);
        /** @var array{supplier_id?: string, expected_date?: string|null, notes?: string|null, lines?: list<array{ingredient_id: string, unit_name: string, qty: string, unit_price: string}>} $data */
        $data = $request->validated();

        return ApiResponse::ok($this->present($this->orders->update($this->actor($request), $purchaseOrder, $data)));
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->visible($request, $purchaseOrder);

        return ApiResponse::ok($this->present($this->orders->submit($this->actor($request), $purchaseOrder)));
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->visible($request, $purchaseOrder);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        return ApiResponse::ok($this->present($this->orders->approve($this->actor($request), $purchaseOrder, $data['note'] ?? null)));
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->visible($request, $purchaseOrder);
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:300']]);

        return ApiResponse::ok($this->present($this->orders->reject($this->actor($request), $purchaseOrder, $data['note'])));
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->visible($request, $purchaseOrder);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return ApiResponse::ok($this->present($this->orders->cancel($this->actor($request), $purchaseOrder, $data['reason'])));
    }

    public function close(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->visible($request, $purchaseOrder);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return ApiResponse::ok($this->present($this->orders->close($this->actor($request), $purchaseOrder, $data['reason'])));
    }

    public function receive(GoodsReceiptRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->visible($request, $purchaseOrder);
        /** @var array{received_at?: string|null, supplier_invoice_no?: string|null, notes?: string|null, lines: list<array{purchase_order_line_id: string, qty: string, unit_price?: string|null}>} $data */
        $data = $request->validated();

        return ApiResponse::created(InventoryResources::receipt($this->receipts->fromPurchaseOrder($this->actor($request), $purchaseOrder, $data)));
    }

    public function receipts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'outlet_id' => ['nullable', 'uuid'],
            'supplier_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $to = $filters['date_to'] ?? InventoryAccess::today();
        $from = $filters['date_from'] ?? CarbonImmutable::parse($to)->subDays(30)->format('Y-m-d');
        $user = $this->actor($request);
        $ids = $this->access->canViewPurchasing($user) ? $this->access->outletIds($user, true) : $this->access->outletIds($user);
        $query = GoodsReceipt::query()
            ->whereIn('outlet_id', $ids)
            ->whereBetween('business_date', [$from, $to])
            ->when($filters['outlet_id'] ?? null, fn ($q, $v) => $q->where('outlet_id', $v))
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->orderByDesc('received_at');

        return ApiResponse::paginated($query->paginate($this->perPage($request)), InventoryResources::receipt(...));
    }

    public function storeReceipt(GoodsReceiptRequest $request): JsonResponse
    {
        /** @var array{location_id: string, supplier_id?: string|null, received_at?: string|null, supplier_invoice_no?: string|null, notes?: string|null, lines: list<array{ingredient_id: string, unit_name: string, qty: string, unit_price: string}>} $data */
        $data = $request->validated();

        return ApiResponse::created(InventoryResources::receipt($this->receipts->manual($this->actor($request), $data)));
    }

    public function receipt(Request $request, GoodsReceipt $receipt): JsonResponse
    {
        $user = $this->actor($request);
        $ids = $this->access->canViewPurchasing($user) ? $this->access->outletIds($user, true) : $this->access->outletIds($user);
        abort_unless(in_array($receipt->outlet_id, $ids, true), 404);

        return ApiResponse::ok(InventoryResources::receipt($receipt->load('lines')));
    }

    /** @return array<string, mixed> */
    private function present(PurchaseOrder $po): array
    {
        return InventoryResources::purchaseOrder($po->loadMissing(['lines', 'supplier']));
    }

    private function visible(Request $request, PurchaseOrder $po): void
    {
        abort_unless(in_array($po->outlet_id, $this->access->outletIds($this->actor($request), true), true), 404);
    }
}
