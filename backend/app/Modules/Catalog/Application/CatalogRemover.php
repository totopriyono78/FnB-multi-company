<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Events\MenuChanged;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\KitchenStation;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use App\Modules\Catalog\Domain\Models\Promotion;
use App\Modules\Shared\Domain\Exceptions\ConflictException;
use Illuminate\Support\Facades\DB;

/** Penghapusan data menu beserta pemeriksaan keterkaitannya. Dipakai API dan back-office. */
class CatalogRemover
{
    public function item(Item $item): void
    {
        $usedInBundle = DB::table('bundle_group_options')
            ->join('bundle_groups', 'bundle_groups.id', '=', 'bundle_group_options.bundle_group_id')
            ->join('items', 'items.id', '=', 'bundle_groups.item_id')
            ->where('bundle_group_options.item_id', $item->id)
            ->whereNull('items.deleted_at')
            ->exists();
        if ($usedInBundle) {
            throw new ConflictException('ITEM_IN_BUNDLE', 'Menu masih menjadi isi paket. Keluarkan dari paket terlebih dahulu.');
        }

        DB::transaction(function () use ($item): void {
            $item->update(['is_active' => false]);
            $item->delete();
        });
        MenuChanged::dispatch($item->company_id, 'item', $item->id);
    }

    public function category(MenuCategory $category): void
    {
        if ($category->items()->exists()) {
            throw new ConflictException('CATEGORY_NOT_EMPTY', 'Kategori masih berisi menu. Pindahkan atau hapus menunya terlebih dahulu.');
        }
        $category->delete();
        MenuChanged::dispatch($category->company_id, 'category', $category->id);
    }

    public function modifierGroup(ModifierGroup $group): void
    {
        if (DB::table('item_modifier_groups')->where('modifier_group_id', $group->id)->exists()) {
            throw new ConflictException('MODIFIER_GROUP_IN_USE', 'Grup masih dipakai menu. Lepaskan dari menu terlebih dahulu.');
        }
        $group->delete();
        MenuChanged::dispatch($group->company_id, 'modifier_group', $group->id);
    }

    public function station(KitchenStation $station): void
    {
        if (Item::query()->where('kitchen_station_id', $station->id)->exists()) {
            throw new ConflictException('STATION_IN_USE', 'Stasiun masih dipakai menu. Pindahkan menu ke stasiun lain atau nonaktifkan stasiun.');
        }
        $station->delete();
    }

    public function promotion(Promotion $promotion): void
    {
        DB::transaction(function () use ($promotion): void {
            $promotion->update(['is_active' => false]);
            $promotion->delete();
        });
        MenuChanged::dispatch($promotion->company_id, 'promotion', $promotion->id);
    }
}
