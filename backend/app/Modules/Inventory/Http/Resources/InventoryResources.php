<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\IngredientUnit;
use App\Modules\Inventory\Domain\Models\Recipe;
use App\Modules\Inventory\Domain\Models\RecipeLine;
use App\Modules\Inventory\Domain\Models\StockAdjustment;
use App\Modules\Inventory\Domain\Models\StockAdjustmentLine;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Domain\Models\StockCount;
use App\Modules\Inventory\Domain\Models\StockCountLine;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Domain\Models\StockTransfer;
use App\Modules\Inventory\Domain\Models\StockTransferLine;
use App\Modules\Purchasing\Domain\Models\GoodsReceipt;
use App\Modules\Purchasing\Domain\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Domain\Models\PurchaseOrder;
use App\Modules\Purchasing\Domain\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Domain\Models\Supplier;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Bentuk respons API inventory & pembelian (SRS §7.4). */
final class InventoryResources
{
    /** @return array<string, mixed> */
    public static function ingredient(Ingredient $i): array
    {
        return [
            'id' => $i->id,
            'code' => $i->code,
            'name' => $i->name,
            'category' => $i->category,
            'base_unit' => $i->base_unit,
            'kind' => $i->kind,
            'min_stock' => $i->min_stock,
            'last_cost' => $i->last_cost,
            'is_active' => $i->is_active,
            'notes' => $i->notes,
            'units' => $i->relationLoaded('units')
                ? $i->units->map(fn (IngredientUnit $u) => ['id' => $u->id, 'name' => $u->name, 'factor' => $u->factor, 'is_purchase_default' => $u->is_purchase_default])->values()->all()
                : null,
            'has_recipe' => $i->relationLoaded('recipe') ? $i->recipe !== null : null,
            'updated_at' => $i->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function location(StockLocation $l): array
    {
        return [
            'id' => $l->id,
            'outlet_id' => $l->outlet_id,
            'code' => $l->code,
            'name' => $l->name,
            'kitchen_station_id' => $l->kitchen_station_id,
            'is_default' => $l->is_default,
            'is_active' => $l->is_active,
        ];
    }

    /**
     * @param  array{brand_id: string|null, label: string, base_unit: string|null}  $target
     * @param  array<string, mixed>|null  $cost
     * @return array<string, mixed>
     */
    public static function recipe(string $type, string $id, array $target, ?Recipe $r, ?array $cost = null): array
    {
        return [
            'target_type' => $type,
            'target_id' => $id,
            'target_label' => $target['label'],
            'brand_id' => $target['brand_id'],
            'exists' => $r !== null,
            'yield_qty' => $r?->yield_qty,
            'yield_unit' => $target['base_unit'],
            'notes' => $r?->notes,
            'lines' => $r === null ? [] : $r->lines->map(fn (RecipeLine $l) => [
                'ingredient_id' => $l->ingredient_id,
                'name' => $l->ingredient->name,
                'base_unit' => $l->ingredient->base_unit,
                'qty' => $l->qty,
            ])->values()->all(),
            'cost' => $cost,
            'updated_at' => $r?->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function balance(StockBalance $b): array
    {
        $min = $b->min_qty ?? $b->ingredient->min_stock;

        return [
            'location_id' => $b->location_id,
            'ingredient_id' => $b->ingredient_id,
            'ingredient' => ['code' => $b->ingredient->code, 'name' => $b->ingredient->name, 'category' => $b->ingredient->category, 'base_unit' => $b->ingredient->base_unit],
            'qty' => $b->qty,
            'avg_cost' => $b->avg_cost,
            'value' => (string) BigDecimal::of((string) $b->qty)->multipliedBy((string) $b->avg_cost)->toScale(2, RoundingMode::HALF_UP),
            'min_qty' => $min,
            'below_minimum' => BigDecimal::of((string) $min)->isPositive() && BigDecimal::of((string) $b->qty)->isLessThan((string) $min),
            'last_movement_at' => $b->last_movement_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function movement(StockMovement $m): array
    {
        return [
            'id' => $m->id,
            'occurred_at' => $m->occurred_at->toIso8601String(),
            'business_date' => $m->business_date->format('Y-m-d'),
            'location_id' => $m->location_id,
            'ingredient_id' => $m->ingredient_id,
            'type' => $m->type,
            'type_label' => StockMovement::TYPES[$m->type] ?? $m->type,
            'qty' => $m->qty,
            'unit_cost' => $m->unit_cost,
            'value' => $m->value,
            'balance_after' => $m->balance_after,
            'reference_type' => $m->reference_type,
            'reference_id' => $m->reference_id,
            'reference_no' => $m->reference_no,
            'reason' => $m->reason,
            'flags' => $m->flags,
            'created_by' => $m->created_by,
        ];
    }

    /** @return array<string, mixed> */
    public static function adjustment(StockAdjustment $a): array
    {
        return [
            'id' => $a->id,
            'number' => $a->number,
            'outlet_id' => $a->outlet_id,
            'location_id' => $a->location_id,
            'type' => $a->type,
            'reason_code' => $a->reason_code,
            'reason_label' => StockAdjustment::reasonLabel($a->type, $a->reason_code),
            'notes' => $a->notes,
            'total_value' => $a->total_value,
            'business_date' => $a->business_date->format('Y-m-d'),
            'occurred_at' => $a->occurred_at->toIso8601String(),
            'created_by' => $a->created_by,
            'lines' => $a->relationLoaded('lines') ? $a->lines->map(fn (StockAdjustmentLine $l) => [
                'ingredient_id' => $l->ingredient_id,
                'qty' => $l->qty,
                'unit_cost' => $l->unit_cost,
                'value' => $l->value,
                'note' => $l->note,
            ])->values()->all() : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function transfer(StockTransfer $t): array
    {
        return [
            'id' => $t->id,
            'number' => $t->number,
            'status' => $t->status,
            'from_outlet_id' => $t->from_outlet_id,
            'from_location_id' => $t->from_location_id,
            'to_outlet_id' => $t->to_outlet_id,
            'to_location_id' => $t->to_location_id,
            'notes' => $t->notes,
            'total_value' => $t->total_value,
            'sent_by' => $t->sent_by,
            'sent_at' => $t->sent_at->toIso8601String(),
            'received_by' => $t->received_by,
            'received_at' => $t->received_at?->toIso8601String(),
            'receive_note' => $t->receive_note,
            'cancelled_at' => $t->cancelled_at?->toIso8601String(),
            'cancel_reason' => $t->cancel_reason,
            'lines' => $t->relationLoaded('lines') ? $t->lines->map(fn (StockTransferLine $l) => [
                'id' => $l->id,
                'ingredient_id' => $l->ingredient_id,
                'qty_sent' => $l->qty_sent,
                'qty_received' => $l->qty_received,
                'unit_cost' => $l->unit_cost,
                'note' => $l->note,
            ])->values()->all() : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function count(StockCount $c): array
    {
        // Hitung buta: saldo sistem dan selisih tidak ditampilkan selama tahap hitung.
        $blind = $c->status === StockCount::COUNTING;

        return [
            'id' => $c->id,
            'number' => $c->number,
            'outlet_id' => $c->outlet_id,
            'location_id' => $c->location_id,
            'scope' => $c->scope,
            'status' => $c->status,
            'notes' => $c->notes,
            'started_at' => $c->started_at->toIso8601String(),
            'started_by' => $c->started_by,
            'submitted_at' => $c->submitted_at?->toIso8601String(),
            'submitted_by' => $c->submitted_by,
            'decided_at' => $c->decided_at?->toIso8601String(),
            'decided_by' => $c->decided_by,
            'decision_note' => $c->decision_note,
            'variance_value' => $c->variance_value,
            'lines' => $c->relationLoaded('lines') ? $c->lines->map(fn (StockCountLine $l) => [
                'ingredient_id' => $l->ingredient_id,
                'system_qty' => $blind ? null : $l->system_qty,
                'counted_qty' => $l->counted_qty,
                'difference' => $blind ? null : $l->difference,
                'unit_cost' => $l->unit_cost,
                'variance_value' => $blind ? null : $l->variance_value,
                'note' => $l->note,
            ])->values()->all() : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function supplier(Supplier $s): array
    {
        return [
            'id' => $s->id,
            'code' => $s->code,
            'name' => $s->name,
            'contact_name' => $s->contact_name,
            'phone' => $s->phone,
            'email' => $s->email,
            'address' => $s->address,
            'payment_term_days' => $s->payment_term_days,
            'notes' => $s->notes,
            'is_active' => $s->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function purchaseOrder(PurchaseOrder $p): array
    {
        return [
            'id' => $p->id,
            'number' => $p->number,
            'status' => $p->status,
            'status_label' => PurchaseOrder::STATUSES[$p->status] ?? $p->status,
            'outlet_id' => $p->outlet_id,
            'location_id' => $p->location_id,
            'supplier_id' => $p->supplier_id,
            'supplier_name' => $p->relationLoaded('supplier') ? $p->supplier->name : null,
            'order_date' => $p->order_date->format('Y-m-d'),
            'expected_date' => $p->expected_date?->format('Y-m-d'),
            'notes' => $p->notes,
            'total' => $p->total,
            'created_by' => $p->created_by,
            'submitted_at' => $p->submitted_at?->toIso8601String(),
            'decided_by' => $p->decided_by,
            'decided_at' => $p->decided_at?->toIso8601String(),
            'decision_note' => $p->decision_note,
            'cancelled_at' => $p->cancelled_at?->toIso8601String(),
            'cancel_reason' => $p->cancel_reason,
            'closed_at' => $p->closed_at?->toIso8601String(),
            'lines' => $p->relationLoaded('lines') ? $p->lines->map(fn (PurchaseOrderLine $l) => [
                'id' => $l->id,
                'ingredient_id' => $l->ingredient_id,
                'unit_name' => $l->unit_name,
                'unit_factor' => $l->unit_factor,
                'qty' => $l->qty,
                'unit_price' => $l->unit_price,
                'line_total' => $l->line_total,
                'received_qty' => $l->received_qty,
            ])->values()->all() : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function receipt(GoodsReceipt $g): array
    {
        return [
            'id' => $g->id,
            'number' => $g->number,
            'outlet_id' => $g->outlet_id,
            'location_id' => $g->location_id,
            'supplier_id' => $g->supplier_id,
            'purchase_order_id' => $g->purchase_order_id,
            'supplier_invoice_no' => $g->supplier_invoice_no,
            'notes' => $g->notes,
            'total' => $g->total,
            'business_date' => $g->business_date->format('Y-m-d'),
            'received_at' => $g->received_at->toIso8601String(),
            'received_by' => $g->received_by,
            'lines' => $g->relationLoaded('lines') ? $g->lines->map(fn (GoodsReceiptLine $l) => [
                'ingredient_id' => $l->ingredient_id,
                'purchase_order_line_id' => $l->purchase_order_line_id,
                'unit_name' => $l->unit_name,
                'qty' => $l->qty,
                'unit_price' => $l->unit_price,
                'line_total' => $l->line_total,
                'base_qty' => $l->base_qty,
                'base_unit_cost' => $l->base_unit_cost,
            ])->values()->all() : null,
        ];
    }
}
