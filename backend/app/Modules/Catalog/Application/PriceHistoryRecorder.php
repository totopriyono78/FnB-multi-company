<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Events\MenuChanged;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemPrice;
use App\Modules\Catalog\Domain\Models\ItemPriceHistory;
use App\Modules\Catalog\Domain\Models\ItemVariant;
use App\Modules\Identity\Domain\Models\User;

/** Mencatat setiap perubahan harga (FR-MENU-15). Dipanggil dari observer model. */
class PriceHistoryRecorder
{
    public function register(): void
    {
        Item::created(fn (Item $item) => $this->record($item->company_id, $item->id, null, null, null, null, (string) $item->base_price));
        Item::updated(function (Item $item): void {
            if ($item->wasChanged('base_price')) {
                $this->record($item->company_id, $item->id, null, null, null, (string) $item->getOriginal('base_price'), (string) $item->base_price);
            }
        });

        ItemVariant::created(fn (ItemVariant $v) => $this->record($v->company_id, $v->item_id, $v->id, null, null, null, (string) $v->price));
        ItemVariant::updated(function (ItemVariant $v): void {
            if ($v->wasChanged('price')) {
                $this->record($v->company_id, $v->item_id, $v->id, null, null, (string) $v->getOriginal('price'), (string) $v->price);
            }
        });
        ItemVariant::deleted(fn (ItemVariant $v) => $this->record($v->company_id, $v->item_id, $v->id, null, null, (string) $v->price, null));

        ItemPrice::created(fn (ItemPrice $p) => $this->record($p->company_id, $p->item_id, $p->item_variant_id, $p->outlet_id, $p->sales_channel_id, null, (string) $p->price));
        ItemPrice::updated(function (ItemPrice $p): void {
            if ($p->wasChanged('price')) {
                $this->record($p->company_id, $p->item_id, $p->item_variant_id, $p->outlet_id, $p->sales_channel_id, (string) $p->getOriginal('price'), (string) $p->price);
            }
        });
        ItemPrice::deleted(fn (ItemPrice $p) => $this->record($p->company_id, $p->item_id, $p->item_variant_id, $p->outlet_id, $p->sales_channel_id, (string) $p->price, null));
    }

    private function record(string $companyId, string $itemId, ?string $variantId, ?string $outletId, ?string $channelId, ?string $old, ?string $new): void
    {
        $user = auth()->user();

        $history = new ItemPriceHistory;
        $history->forceFill([
            'company_id' => $companyId,
            'item_id' => $itemId,
            'item_variant_id' => $variantId,
            'outlet_id' => $outletId,
            'sales_channel_id' => $channelId,
            'old_price' => $old,
            'new_price' => $new,
            'changed_by' => $user instanceof User ? $user->id : null,
        ])->save();

        MenuChanged::dispatch($companyId, 'item', $itemId);
    }
}
