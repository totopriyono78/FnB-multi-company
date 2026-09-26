<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Events\MenuChanged;
use App\Modules\Catalog\Domain\Models\BundleGroup;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemVariant;
use App\Modules\Catalog\Domain\Models\KitchenStation;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Shared\Application\MediaStore;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuat/mengubah item beserta varian, grup modifier, dan isi paket (FR-MENU-02..05, FR-MENU-14).
 * Semua referensi divalidasi berada di brand yang sama.
 */
class ItemWriter
{
    public function __construct(private readonly MediaStore $media) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(?Item $item, array $data): Item
    {
        $brandId = (string) ($data['brand_id'] ?? $item?->brand_id);
        $this->assertReferences($brandId, $data, $item);

        $fotoLama = $item?->image_path;

        $tersimpan = DB::transaction(function () use ($item, $data): Item {
            $item ??= new Item;
            $item->fill(Arr::only($data, [
                'brand_id', 'category_id', 'type', 'sku', 'barcode', 'name', 'short_name', 'description', 'image_path',
                'base_price', 'kitchen_station_id', 'channel_codes', 'schedule', 'sort_order', 'is_active',
                'sold_by_weight', 'unit',
            ]));
            if (! $item->exists && empty($item->short_name)) {
                $item->short_name = mb_substr((string) $item->name, 0, 24);
            }
            $item->save();

            if (array_key_exists('variants', $data)) {
                $this->syncVariants($item, $data['variants'] ?? []);
            }
            if (array_key_exists('modifier_group_ids', $data)) {
                $sync = [];
                foreach (array_values($data['modifier_group_ids'] ?? []) as $i => $groupId) {
                    $sync[$groupId] = ['sort_order' => $i, 'company_id' => $item->company_id];
                }
                $item->modifierGroups()->sync($sync);
            }
            if (array_key_exists('bundle_groups', $data)) {
                $this->syncBundle($item, $data['bundle_groups'] ?? []);
            }

            MenuChanged::dispatch($item->company_id, 'item', $item->id);

            return $item->refresh()->load(['variants', 'modifierGroups', 'bundleGroups.options', 'category', 'station']);
        });

        // Foto yang digantikan baru dibuang setelah penyimpanan benar-benar berhasil.
        if (array_key_exists('image_path', $data) && $tersimpan->image_path !== $fotoLama) {
            $this->media->forget($fotoLama);
        }

        return $tersimpan;
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     */
    private function syncVariants(Item $item, array $variants): void
    {
        $keep = [];
        $hasDefault = collect($variants)->contains(fn ($v) => (bool) ($v['is_default'] ?? false));

        foreach (array_values($variants) as $i => $row) {
            $variant = isset($row['id'])
                ? ItemVariant::query()->where('item_id', $item->id)->findOrFail($row['id'])
                : new ItemVariant;
            $variant->item_id = $item->id;
            $variant->fill([
                'name' => $row['name'],
                'sku' => $row['sku'] ?? null,
                'price' => $row['price'],
                'is_default' => $hasDefault ? (bool) ($row['is_default'] ?? false) : $i === 0,
                'sort_order' => $row['sort_order'] ?? $i,
                'is_active' => $row['is_active'] ?? true,
            ])->save();
            $keep[] = $variant->id;
        }

        ItemVariant::query()->where('item_id', $item->id)->whereNotIn('id', $keep)->get()->each->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     */
    private function syncBundle(Item $item, array $groups): void
    {
        $item->bundleGroups()->get()->each->delete();

        foreach (array_values($groups) as $i => $row) {
            $group = new BundleGroup([
                'name' => $row['name'],
                'min_select' => $row['min_select'] ?? 1,
                'max_select' => $row['max_select'] ?? 1,
                'sort_order' => $i,
            ]);
            $group->item_id = $item->id;
            $group->save();

            foreach (array_values($row['options'] ?? []) as $j => $option) {
                $group->options()->create([
                    'item_id' => $option['item_id'],
                    'item_variant_id' => $option['item_variant_id'] ?? null,
                    'extra_price' => $option['extra_price'] ?? '0',
                    'is_default' => (bool) ($option['is_default'] ?? false),
                    'sort_order' => $j,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertReferences(string $brandId, array $data, ?Item $item): void
    {
        $errors = [];

        if (isset($data['category_id']) && ! MenuCategory::query()->whereKey($data['category_id'])->where('brand_id', $brandId)->exists()) {
            $errors['category_id'] = 'Kategori tidak ditemukan di brand ini.';
        }
        if (! empty($data['kitchen_station_id']) && ! KitchenStation::query()->whereKey($data['kitchen_station_id'])->exists()) {
            $errors['kitchen_station_id'] = 'Stasiun dapur tidak ditemukan.';
        }

        if (isset($data['type']) && ! in_array($data['type'], [Item::TYPE_SINGLE, Item::TYPE_BUNDLE], true)) {
            $errors['type'] = 'Jenis menu tidak dikenal.';
        }
        $channels = array_values(array_unique($data['channel_codes'] ?? []));
        if ($channels !== [] && SalesChannel::query()->whereIn('code', $channels)->count() !== count($channels)) {
            $errors['channel_codes'] = 'Channel penjualan tidak dikenal.';
        }

        $groupIds = $data['modifier_group_ids'] ?? [];
        if ($groupIds !== [] && ModifierGroup::query()->whereIn('id', $groupIds)->where('brand_id', $brandId)->count() !== count(array_unique($groupIds))) {
            $errors['modifier_group_ids'] = 'Grup modifier harus berasal dari brand yang sama.';
        }

        $type = $data['type'] ?? $item->type ?? Item::TYPE_SINGLE;
        $bundle = $data['bundle_groups'] ?? [];
        if ($type === Item::TYPE_BUNDLE && array_key_exists('bundle_groups', $data) && $bundle === []) {
            $errors['bundle_groups'] = 'Paket harus memiliki minimal satu grup pilihan.';
        }
        if ($type !== Item::TYPE_BUNDLE && $bundle !== []) {
            $errors['bundle_groups'] = 'Hanya item bertipe paket yang boleh memiliki isi paket.';
        }
        if ($type === Item::TYPE_BUNDLE && ! empty($data['variants'])) {
            $errors['variants'] = 'Paket tidak memakai varian. Atur pilihan di isi paket.';
        }

        foreach ($bundle as $g => $group) {
            $options = $group['options'] ?? [];
            if ($options === []) {
                $errors["bundle_groups.{$g}.options"] = 'Setiap grup paket harus memiliki pilihan.';

                continue;
            }
            if (($group['max_select'] ?? 1) < ($group['min_select'] ?? 1)) {
                $errors["bundle_groups.{$g}.max_select"] = 'Maksimal pilihan tidak boleh lebih kecil dari minimal.';
            }
            $itemIds = array_unique(array_column($options, 'item_id'));
            $valid = Item::query()->whereIn('id', $itemIds)->where('brand_id', $brandId)->where('type', Item::TYPE_SINGLE)
                ->when($item?->id, fn ($q, $id) => $q->whereKeyNot($id))->count();
            if ($valid !== count($itemIds)) {
                $errors["bundle_groups.{$g}.options"] = 'Isi paket harus item biasa dari brand yang sama.';
            }
            foreach ($options as $o => $option) {
                if (! empty($option['item_variant_id'])
                    && ! ItemVariant::query()->whereKey($option['item_variant_id'])->where('item_id', $option['item_id'])->exists()) {
                    $errors["bundle_groups.{$g}.options.{$o}.item_variant_id"] = 'Varian tidak sesuai dengan item.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
