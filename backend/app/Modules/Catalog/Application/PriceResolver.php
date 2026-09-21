<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemPrice;
use App\Modules\Catalog\Domain\Models\ItemVariant;
use Illuminate\Support\Collection;

/**
 * Harga item untuk outlet & channel tertentu (FR-MENU-06).
 * Prioritas: outlet+channel → outlet → channel → harga varian/harga dasar.
 */
class PriceResolver
{
    /**
     * @param  Collection<int, ItemPrice>|null  $overrides  harga khusus item (hindari query berulang)
     */
    public function resolve(Item $item, ?ItemVariant $variant, ?string $outletId, ?string $channelId, ?Collection $overrides = null): string
    {
        $overrides ??= $item->relationLoaded('prices') ? $item->prices : $item->prices()->get();
        $variantId = $variant?->id;
        $candidates = $overrides->filter(fn (ItemPrice $p) => $p->item_variant_id === $variantId);

        $tiers = [];
        if ($outletId !== null && $channelId !== null) {
            $tiers[] = [$outletId, $channelId];
        }
        if ($outletId !== null) {
            $tiers[] = [$outletId, null];
        }
        if ($channelId !== null) {
            $tiers[] = [null, $channelId];
        }

        foreach ($tiers as [$o, $c]) {
            $match = $candidates->first(fn (ItemPrice $p) => $p->outlet_id === $o && $p->sales_channel_id === $c);
            if ($match !== null) {
                return (string) $match->price;
            }
        }

        return (string) ($variant !== null ? $variant->price : $item->base_price);
    }
}
