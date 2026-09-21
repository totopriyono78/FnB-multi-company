<?php

namespace App\Modules\Purchasing\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\InventoryException;
use App\Modules\Inventory\Application\StockDocumentService;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Purchasing\Domain\Models\GoodsReceipt;
use App\Modules\Purchasing\Domain\Models\PurchaseOrder;
use App\Modules\Purchasing\Domain\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Sales\Application\BusinessCalendar;
use App\Modules\Shared\Support\DocumentNumber;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Penerimaan barang dari PO atau tanpa PO (FR-INV-05). Menambah stok & memperbarui HPP rata-rata.
 */
class GoodsReceiptService
{
    public function __construct(
        private readonly InventoryAccess $access,
        private readonly StockDocumentService $documents,
        private readonly PurchaseOrderService $orders,
        private readonly StockLedger $ledger,
        private readonly BusinessCalendar $calendar,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{received_at?: string|null, supplier_invoice_no?: string|null, notes?: string|null,
     *     lines: list<array{purchase_order_line_id: string, qty: string|int|float, unit_price?: string|int|float|null}>}  $data
     */
    public function fromPurchaseOrder(User $actor, PurchaseOrder $po, array $data): GoodsReceipt
    {
        $po->loadMissing(['location.outlet']);
        if (! $this->access->canReceive($actor, $po->location->outlet)) {
            throw new AuthorizationException('Anda tidak memiliki izin menerima barang di outlet ini.');
        }
        $at = $this->time($data['received_at'] ?? null);

        return DB::transaction(function () use ($actor, $po, $data, $at): GoodsReceipt {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, PurchaseOrder::RECEIVABLE, true)) {
                throw new InventoryException('PO_NOT_RECEIVABLE', 'Barang hanya dapat diterima untuk PO yang sudah disetujui dan belum selesai.', 409, details: ['status' => $locked->status]);
            }
            $poLines = PurchaseOrderLine::query()->with('ingredient')->where('purchase_order_id', $locked->id)->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            $priceChanged = false;
            $seen = [];
            foreach ($data['lines'] as $i => $line) {
                /** @var PurchaseOrderLine|null $poLine */
                $poLine = $poLines->get($line['purchase_order_line_id']);
                if ($poLine === null || isset($seen[$poLine->id])) {
                    throw new InventoryException('LINE_UNKNOWN', 'Baris PO tidak dikenal atau diisi dua kali.', 422, "lines.{$i}.purchase_order_line_id");
                }
                $seen[$poLine->id] = true;
                $qty = BigDecimal::of((string) $line['qty']);
                if ($qty->isZero()) {
                    continue;
                }
                $remaining = BigDecimal::of((string) $poLine->qty)->minus((string) $poLine->received_qty);
                if ($qty->isNegative() || $qty->isGreaterThan($remaining)) {
                    throw new InventoryException('QTY_EXCEEDS_ORDER', "Jumlah diterima {$poLine->ingredient->name} melebihi sisa pesanan ({$remaining->strippedOfTrailingZeros()} {$poLine->unit_name}).", 422, "lines.{$i}.qty");
                }
                $price = isset($line['unit_price']) ? BigDecimal::of((string) $line['unit_price'])->toScale(2, RoundingMode::HALF_UP) : BigDecimal::of((string) $poLine->unit_price);
                $priceChanged = $priceChanged || ! $price->isEqualTo((string) $poLine->unit_price);
                $lines[] = [
                    'purchase_order_line_id' => $poLine->id,
                    'ingredient_id' => $poLine->ingredient_id,
                    'unit_name' => $poLine->unit_name,
                    'unit_factor' => (string) $poLine->unit_factor,
                    'qty' => $qty,
                    'unit_price' => $price,
                ];
                PurchaseOrderLine::query()->whereKey($poLine->id)->update(['received_qty' => (string) BigDecimal::of((string) $poLine->received_qty)->plus($qty)->toScale(4)]);
            }
            if ($lines === []) {
                throw new InventoryException('RECEIPT_EMPTY', 'Isi jumlah barang yang diterima.', 422, 'lines');
            }

            $receipt = $this->store($actor, $po->location, $locked->supplier_id, $locked, $lines, $at, $data);

            $complete = PurchaseOrderLine::query()->where('purchase_order_id', $locked->id)->whereColumn('received_qty', '<', 'qty')->doesntExist();
            $locked->forceFill(['status' => $complete ? PurchaseOrder::RECEIVED : PurchaseOrder::PARTIALLY_RECEIVED])->save();
            $this->audit->log('goods_receipt.created', $receipt, new: [
                'number' => $receipt->number,
                'purchase_order' => $locked->number,
                'total' => $receipt->total,
                'po_status' => $locked->status,
                'price_changed' => $priceChanged,
            ], userId: $actor->id);

            return $receipt->load('lines');
        });
    }

    /**
     * @param  array{location_id: string, supplier_id?: string|null, received_at?: string|null, supplier_invoice_no?: string|null, notes?: string|null,
     *     lines: list<array{ingredient_id: string, unit_name: string, qty: string|int|float, unit_price: string|int|float}>}  $data
     */
    public function manual(User $actor, array $data): GoodsReceipt
    {
        $location = $this->documents->location($data['location_id'], 'location_id');
        if (! $this->access->canReceive($actor, $location->outlet)) {
            throw new AuthorizationException('Anda tidak memiliki izin menerima barang di outlet ini.');
        }
        $supplierId = null;
        if (! empty($data['supplier_id'])) {
            $supplier = Str::isUuid($data['supplier_id']) ? Supplier::query()->find($data['supplier_id']) : null;
            if ($supplier === null) {
                throw new InventoryException('SUPPLIER_UNKNOWN', 'Pemasok tidak ditemukan.', 422, 'supplier_id');
            }
            $supplierId = $supplier->id;
        }
        $this->documents->stockable(array_column($data['lines'], 'ingredient_id'));
        $parsed = $this->orders->lines($data['lines']);
        $at = $this->time($data['received_at'] ?? null);

        return DB::transaction(function () use ($actor, $data, $location, $supplierId, $parsed, $at): GoodsReceipt {
            $lines = array_map(fn (array $l) => [
                'purchase_order_line_id' => null,
                'ingredient_id' => $l['ingredient_id'],
                'unit_name' => $l['unit_name'],
                'unit_factor' => $l['unit_factor'],
                'qty' => BigDecimal::of($l['qty']),
                'unit_price' => BigDecimal::of($l['unit_price']),
            ], $parsed);
            $receipt = $this->store($actor, $location, $supplierId, null, $lines, $at, $data);
            $this->audit->log('goods_receipt.created', $receipt, new: ['number' => $receipt->number, 'total' => $receipt->total, 'without_po' => true], userId: $actor->id);

            return $receipt->load('lines');
        });
    }

    /**
     * @param  list<array{purchase_order_line_id: string|null, ingredient_id: string, unit_name: string, unit_factor: string, qty: BigDecimal, unit_price: BigDecimal}>  $lines
     * @param  array<string, mixed>  $data
     */
    private function store(User $actor, StockLocation $location, ?string $supplierId, ?PurchaseOrder $po, array $lines, CarbonImmutable $at, array $data): GoodsReceipt
    {
        $outlet = $location->outlet;
        $id = (string) Str::uuid7();
        $number = DocumentNumber::next('GR', $outlet->code, $at->setTimezone($outlet->timezone));
        $businessDate = $this->calendar->businessDate($outlet, $at)->format('Y-m-d');

        $total = BigDecimal::zero();
        $rows = [];
        $entries = [];
        foreach ($lines as $l) {
            $factor = BigDecimal::of($l['unit_factor']);
            $lineTotal = $l['qty']->multipliedBy($l['unit_price'])->toScale(2, RoundingMode::HALF_UP);
            $baseQty = $l['qty']->multipliedBy($factor)->toScale(StockLedger::QTY_SCALE, RoundingMode::HALF_UP);
            $baseCost = $l['unit_price']->dividedBy($factor, StockLedger::COST_SCALE, RoundingMode::HALF_UP);
            $total = $total->plus($lineTotal);
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'company_id' => $location->company_id,
                'goods_receipt_id' => $id,
                'purchase_order_line_id' => $l['purchase_order_line_id'],
                'ingredient_id' => $l['ingredient_id'],
                'unit_name' => $l['unit_name'],
                'unit_factor' => (string) $factor,
                'qty' => (string) $l['qty']->toScale(4, RoundingMode::HALF_UP),
                'unit_price' => (string) $l['unit_price']->toScale(2),
                'line_total' => (string) $lineTotal,
                'base_qty' => (string) $baseQty,
                'base_unit_cost' => (string) $baseCost,
            ];
            $entries[] = [
                'location' => $location,
                'ingredient_id' => $l['ingredient_id'],
                'qty' => $baseQty,
                'unit_cost' => $baseCost,
                'type' => 'receipt',
                'reference_type' => 'goods_receipt',
                'reference_id' => $id,
                'reference_no' => $number,
                'reason' => $po !== null ? 'Penerimaan '.$po->number : 'Penerimaan tanpa PO',
                'business_date' => $businessDate,
                'occurred_at' => $at,
                'created_by' => $actor->id,
            ];
        }

        $receipt = new GoodsReceipt;
        $receipt->forceFill([
            'id' => $id,
            'number' => $number,
            'outlet_id' => $outlet->id,
            'location_id' => $location->id,
            'supplier_id' => $supplierId,
            'purchase_order_id' => $po?->id,
            'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
            'notes' => $data['notes'] ?? null,
            'total' => (string) $total->toScale(2),
            'business_date' => $businessDate,
            'received_at' => $at,
            'received_by' => $actor->id,
        ])->save();
        DB::table('goods_receipt_lines')->insert($rows);
        $this->ledger->post($entries);

        return $receipt;
    }

    private function time(?string $value): CarbonImmutable
    {
        $at = $value === null ? now()->toImmutable() : CarbonImmutable::parse($value)->utc();
        if ($at->greaterThan(now()->addMinutes(5))) {
            throw new InventoryException('FUTURE_TIME', 'Waktu penerimaan tidak boleh di masa depan.', 422, 'received_at');
        }
        if ($at->lessThan(now()->subDays(StockDocumentService::MAX_BACKDATE_DAYS))) {
            throw new InventoryException('TOO_OLD', 'Penerimaan hanya dapat dicatat mundur maksimal '.StockDocumentService::MAX_BACKDATE_DAYS.' hari.', 422, 'received_at');
        }

        return $at;
    }
}
