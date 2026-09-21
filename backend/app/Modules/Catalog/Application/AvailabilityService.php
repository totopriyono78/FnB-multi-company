<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Catalog\Domain\Events\ItemAvailabilityChanged;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\OutletItemAvailability;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;

/** Status dijual/habis per outlet (FR-MENU-07, FR-MENU-08). */
class AvailabilityService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function set(Item $item, Outlet $outlet, ?bool $listed, ?bool $soldOut, ?User $actor): OutletItemAvailability
    {
        abort_unless($item->brand_id === $outlet->brand_id, 422, 'Item tidak dijual di brand outlet ini.');

        $row = OutletItemAvailability::query()->firstOrNew(['outlet_id' => $outlet->id, 'item_id' => $item->id]);
        $before = $row->exists ? ['is_listed' => $row->is_listed, 'is_sold_out' => $row->is_sold_out] : ['is_listed' => true, 'is_sold_out' => false];

        if ($listed !== null) {
            $row->is_listed = $listed;
        }
        if ($soldOut !== null) {
            $row->is_sold_out = $soldOut;
            $row->sold_out_at = $soldOut ? CarbonImmutable::now() : null;
            $row->sold_out_by = $soldOut ? $actor?->id : null;
        }
        $row->is_listed ??= true;
        $row->is_sold_out ??= false;
        $row->save();

        $after = ['is_listed' => $row->is_listed, 'is_sold_out' => $row->is_sold_out];
        if ($before !== $after) {
            $this->audit->log('item.availability_changed', $item, $before, $after, metadata: ['outlet_id' => $outlet->id]);
            if ($before['is_sold_out'] !== $after['is_sold_out']) {
                ItemAvailabilityChanged::dispatch($item->company_id, $outlet->id, $item->id, $row->is_sold_out);
            }
        }

        return $row;
    }
}
