<?php

namespace App\Modules\Purchasing\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\InventoryException;
use App\Modules\Inventory\Application\StockDocumentService;
use App\Modules\Inventory\Application\UnitConverter;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Purchasing\Domain\Models\PurchaseOrder;
use App\Modules\Purchasing\Domain\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Shared\Support\DocumentNumber;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Purchase order (FR-PUR): draf → diajukan → disetujui/ditolak → diterima.
 * Harga dicatat apa adanya per satuan beli; pajak pembelian belum dihitung otomatis (menunggu keputusan user).
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly InventoryAccess $access,
        private readonly StockDocumentService $documents,
        private readonly UnitConverter $units,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{location_id: string, supplier_id: string, order_date?: string|null, expected_date?: string|null, notes?: string|null,
     *     lines: list<array{ingredient_id: string, unit_name: string, qty: string|int|float, unit_price: string|int|float}>}  $data
     */
    public function create(User $actor, array $data): PurchaseOrder
    {
        $location = $this->documents->location($data['location_id'], 'location_id');
        if (! $this->access->canRequestPurchase($actor, $location->outlet)) {
            throw new AuthorizationException('Anda tidak memiliki izin membuat purchase order untuk outlet ini.');
        }
        $supplier = $this->supplier($data['supplier_id']);
        $this->documents->stockable(array_column($data['lines'], 'ingredient_id'));
        $lines = $this->lines($data['lines']);

        return DB::transaction(function () use ($actor, $data, $location, $supplier, $lines): PurchaseOrder {
            $outlet = $location->outlet;
            $orderDate = isset($data['order_date']) ? CarbonImmutable::parse($data['order_date']) : now()->setTimezone($outlet->timezone)->toImmutable();
            $po = new PurchaseOrder;
            $po->forceFill([
                'id' => (string) Str::uuid7(),
                'number' => DocumentNumber::next('PO', $outlet->code, $orderDate),
                'outlet_id' => $outlet->id,
                'location_id' => $location->id,
                'supplier_id' => $supplier->id,
                'status' => PurchaseOrder::DRAFT,
                'order_date' => $orderDate->format('Y-m-d'),
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'total' => '0',
                'created_by' => $actor->id,
            ])->save();
            $this->replaceLines($po, $lines);
            $this->audit->log('purchase_order.created', $po, new: ['number' => $po->number, 'supplier_id' => $supplier->id, 'total' => $po->total], userId: $actor->id);

            return $po->load('lines');
        });
    }

    /**
     * @param  array{supplier_id?: string, expected_date?: string|null, notes?: string|null,
     *     lines?: list<array{ingredient_id: string, unit_name: string, qty: string|int|float, unit_price: string|int|float}>}  $data
     */
    public function update(User $actor, PurchaseOrder $po, array $data): PurchaseOrder
    {
        $this->assertRequester($actor, $po);
        $supplier = isset($data['supplier_id']) ? $this->supplier($data['supplier_id']) : null;
        if (isset($data['lines'])) {
            $this->documents->stockable(array_column($data['lines'], 'ingredient_id'));
        }
        $lines = isset($data['lines']) ? $this->lines($data['lines']) : null;

        return DB::transaction(function () use ($actor, $po, $data, $supplier, $lines): PurchaseOrder {
            $locked = $this->lock($po);
            if (! $locked->isEditable()) {
                throw new InventoryException('PO_NOT_EDITABLE', 'Hanya purchase order berstatus draf yang dapat diubah.');
            }
            $before = $locked->only(['supplier_id', 'expected_date', 'notes', 'total']);
            if ($supplier !== null) {
                $locked->supplier_id = $supplier->id;
            }
            if (array_key_exists('expected_date', $data)) {
                $locked->forceFill(['expected_date' => $data['expected_date']]);
            }
            if (array_key_exists('notes', $data)) {
                $locked->notes = $data['notes'];
            }
            $locked->save();
            if ($lines !== null) {
                $this->replaceLines($locked, $lines);
            }
            $this->audit->log('purchase_order.updated', $locked, old: $before, new: $locked->refresh()->only(['supplier_id', 'expected_date', 'notes', 'total']), userId: $actor->id);

            return $locked->load('lines');
        });
    }

    public function submit(User $actor, PurchaseOrder $po): PurchaseOrder
    {
        $this->assertRequester($actor, $po);

        return $this->transition($po, [PurchaseOrder::DRAFT], PurchaseOrder::SUBMITTED, function (PurchaseOrder $locked) use ($actor): void {
            if ($locked->lines->isEmpty()) {
                throw new InventoryException('PO_EMPTY', 'Purchase order belum berisi bahan.', 422, 'lines');
            }
            $locked->forceFill(['submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->audit->log('purchase_order.submitted', $locked, new: ['number' => $locked->number, 'total' => $locked->total], userId: $actor->id);
        });
    }

    public function approve(User $actor, PurchaseOrder $po, ?string $note): PurchaseOrder
    {
        $this->assertApprover($actor, $po);

        return $this->transition($po, [PurchaseOrder::SUBMITTED], PurchaseOrder::APPROVED, function (PurchaseOrder $locked) use ($actor, $note): void {
            $locked->forceFill(['decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
            $this->audit->log('purchase_order.approved', $locked, new: [
                'number' => $locked->number,
                'total' => $locked->total,
                'self_approved' => in_array($actor->id, [$locked->created_by, $locked->submitted_by], true),
            ], reason: $note, userId: $actor->id);
        });
    }

    public function reject(User $actor, PurchaseOrder $po, string $note): PurchaseOrder
    {
        $this->assertApprover($actor, $po);

        return $this->transition($po, [PurchaseOrder::SUBMITTED], PurchaseOrder::REJECTED, function (PurchaseOrder $locked) use ($actor, $note): void {
            $locked->forceFill(['decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
            $this->audit->log('purchase_order.rejected', $locked, new: ['number' => $locked->number], reason: $note, userId: $actor->id);
        });
    }

    public function cancel(User $actor, PurchaseOrder $po, string $reason): PurchaseOrder
    {
        $po->loadMissing('outlet');
        $allowed = $po->status === PurchaseOrder::APPROVED
            ? $this->access->canManagePurchase($actor, $po->outlet)
            : $this->canRequesterAct($actor, $po);
        if (! $allowed) {
            throw new AuthorizationException('Anda tidak memiliki izin membatalkan purchase order ini.');
        }

        return $this->transition($po, [PurchaseOrder::DRAFT, PurchaseOrder::SUBMITTED, PurchaseOrder::APPROVED], PurchaseOrder::CANCELLED, function (PurchaseOrder $locked) use ($actor, $reason): void {
            $locked->forceFill(['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $this->audit->log('purchase_order.cancelled', $locked, new: ['number' => $locked->number], reason: $reason, userId: $actor->id);
        });
    }

    /** Tutup PO yang diterima sebagian (sisa tidak akan dikirim pemasok). */
    public function close(User $actor, PurchaseOrder $po, string $reason): PurchaseOrder
    {
        $po->loadMissing('outlet');
        if (! $this->access->canManagePurchase($actor, $po->outlet)) {
            throw new AuthorizationException('Anda tidak memiliki izin menutup purchase order ini.');
        }

        return $this->transition($po, [PurchaseOrder::PARTIALLY_RECEIVED], PurchaseOrder::CLOSED, function (PurchaseOrder $locked) use ($actor, $reason): void {
            $locked->forceFill(['closed_at' => now(), 'decision_note' => $reason]);
            $this->audit->log('purchase_order.closed', $locked, new: ['number' => $locked->number], reason: $reason, userId: $actor->id);
        });
    }

    public function canRequesterAct(User $actor, PurchaseOrder $po): bool
    {
        $po->loadMissing('outlet');
        if ($this->access->canManagePurchase($actor, $po->outlet)) {
            return true;
        }

        // Pengaju (mis. manajer outlet) hanya mengelola PO buatannya sendiri.
        return $po->created_by === $actor->id && $this->access->canRequestPurchase($actor, $po->outlet);
    }

    private function assertRequester(User $actor, PurchaseOrder $po): void
    {
        if (! $this->canRequesterAct($actor, $po)) {
            throw new AuthorizationException('Anda tidak memiliki izin mengubah purchase order ini.');
        }
    }

    private function assertApprover(User $actor, PurchaseOrder $po): void
    {
        $po->loadMissing('outlet');
        if (! $this->access->canApprovePurchase($actor, $po->outlet)) {
            throw new AuthorizationException('Persetujuan purchase order hanya oleh pemegang izin persetujuan.');
        }
    }

    /**
     * @param  list<string>  $from
     * @param  \Closure(PurchaseOrder): void  $apply
     */
    private function transition(PurchaseOrder $po, array $from, string $to, \Closure $apply): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $from, $to, $apply): PurchaseOrder {
            $locked = $this->lock($po);
            if (! in_array($locked->status, $from, true)) {
                throw new InventoryException('PO_INVALID_STATUS', 'Status purchase order tidak memungkinkan aksi ini ('.PurchaseOrder::STATUSES[$locked->status].').', 409, details: ['status' => $locked->status]);
            }
            $locked->status = $to;
            $apply($locked);
            $locked->save();

            return $locked->refresh()->load('lines');
        });
    }

    private function lock(PurchaseOrder $po): PurchaseOrder
    {
        /** @var PurchaseOrder $locked */
        $locked = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();

        return $locked->load('lines');
    }

    private function supplier(string $id): Supplier
    {
        /** @var Supplier|null $supplier */
        $supplier = Str::isUuid($id) ? Supplier::query()->find($id) : null;
        if ($supplier === null || ! $supplier->is_active) {
            throw new InventoryException('SUPPLIER_UNKNOWN', 'Pemasok tidak ditemukan atau nonaktif.', 422, 'supplier_id');
        }

        return $supplier;
    }

    /**
     * @param  list<array{ingredient_id: string, unit_name: string, qty: string|int|float, unit_price: string|int|float}>  $lines
     * @return list<array<string, string>>
     */
    public function lines(array $lines): array
    {
        if ($lines === []) {
            throw new InventoryException('PO_EMPTY', 'Isi minimal satu bahan.', 422, 'lines');
        }
        $ingredients = Ingredient::query()->with('units')->whereIn('id', array_column($lines, 'ingredient_id'))->get()->keyBy('id');
        $result = [];
        foreach ($lines as $i => $line) {
            /** @var Ingredient|null $ingredient */
            $ingredient = $ingredients->get($line['ingredient_id']);
            if ($ingredient === null || ! $ingredient->is_active) {
                throw new InventoryException('INGREDIENT_UNKNOWN', 'Bahan tidak ditemukan atau nonaktif.', 422, "lines.{$i}.ingredient_id");
            }
            $factor = $this->units->factor($ingredient, (string) $line['unit_name'], "lines.{$i}.unit_name");
            $qty = BigDecimal::of((string) $line['qty']);
            $price = BigDecimal::of((string) $line['unit_price'])->toScale(2, RoundingMode::HALF_UP);
            if (! $qty->isPositive()) {
                throw new InventoryException('INVALID_QTY', 'Jumlah harus lebih dari 0.', 422, "lines.{$i}.qty");
            }
            $result[] = [
                'ingredient_id' => $ingredient->id,
                'unit_name' => (string) $line['unit_name'],
                'unit_factor' => (string) $factor,
                'qty' => (string) $qty->toScale(4, RoundingMode::HALF_UP),
                'unit_price' => (string) $price,
                'line_total' => (string) $qty->multipliedBy($price)->toScale(2, RoundingMode::HALF_UP),
            ];
        }

        return $result;
    }

    /** @param  list<array<string, string>>  $lines */
    private function replaceLines(PurchaseOrder $po, array $lines): void
    {
        PurchaseOrderLine::query()->where('purchase_order_id', $po->id)->delete();
        $total = BigDecimal::zero();
        $rows = [];
        foreach ($lines as $n => $line) {
            $total = $total->plus($line['line_total']);
            $rows[] = $line + [
                'id' => (string) Str::uuid7(),
                'company_id' => $po->company_id,
                'purchase_order_id' => $po->id,
                'received_qty' => '0',
                'sort_order' => $n,
            ];
        }
        DB::table('purchase_order_lines')->insert($rows);
        $po->forceFill(['total' => (string) $total->toScale(2)])->save();
    }
}
