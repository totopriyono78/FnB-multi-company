<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Events\MenuChanged;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\Promotion;
use App\Modules\Catalog\Domain\Models\PromotionTarget;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\WritableCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Menyimpan promo beserta target menu/kategori dan outletnya (FR-MENU-11, FR-MENU-12). */
class PromotionWriter
{
    public function __construct(private readonly MenuScope $scope) {}

    /**
     * Data sudah divalidasi formatnya. item_ids/category_ids/outlet_ids yang tidak dikirim tidak diubah.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, Promotion $promotion, array $data): Promotion
    {
        $brandId = $promotion->exists ? $promotion->brand_id : ($data['brand_id'] ?? null);
        if (! WritableCompany::allows() || ! $this->canManage($actor, $brandId)) {
            throw new AuthorizationException('Anda tidak mengelola promo untuk brand ini.');
        }

        /** @var list<string>|null $itemIds */
        $itemIds = array_key_exists('item_ids', $data) ? array_values($data['item_ids'] ?? []) : null;
        /** @var list<string>|null $categoryIds */
        $categoryIds = array_key_exists('category_ids', $data) ? array_values($data['category_ids'] ?? []) : null;
        /** @var list<string>|null $outletIds */
        $outletIds = array_key_exists('outlet_ids', $data) ? array_values($data['outlet_ids'] ?? []) : null;

        // Semua referensi harus ada di company aktif (global scope + RLS) dan, bila promo milik brand, di brand tersebut.
        $this->assertOwned($itemIds, Item::query(), $brandId, 'item_ids', 'Menu promo tidak ditemukan di brand ini.');
        $this->assertOwned($categoryIds, MenuCategory::query(), $brandId, 'category_ids', 'Kategori promo tidak ditemukan di brand ini.');
        $this->assertOwned($outletIds, Outlet::query(), $brandId, 'outlet_ids', 'Outlet promo tidak ditemukan di brand ini.');

        return DB::transaction(function () use ($promotion, $data, $itemIds, $categoryIds, $outletIds): Promotion {
            $attributes = Arr::except($data, ['item_ids', 'category_ids', 'outlet_ids']);
            if ($promotion->exists) {
                unset($attributes['brand_id']); // brand promo tidak dapat dipindah
            }
            $promotion->fill($attributes)->save();

            foreach (['item' => $itemIds, 'category' => $categoryIds] as $type => $ids) {
                if ($ids === null) {
                    continue;
                }
                $promotion->targets()->where('target_type', $type)->delete();
                foreach (array_unique($ids) as $id) {
                    PromotionTarget::query()->create(['promotion_id' => $promotion->id, 'target_type' => $type, 'target_id' => $id]);
                }
            }
            if ($outletIds !== null) {
                $sync = [];
                foreach ($outletIds as $outletId) {
                    $sync[$outletId] = ['company_id' => $promotion->company_id];
                }
                $promotion->outlets()->sync($sync);
            }

            MenuChanged::dispatch($promotion->company_id, 'promotion', $promotion->id);

            return $promotion->refresh()->load(['targets', 'outlets']);
        });
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  list<string>|null  $ids
     * @param  Builder<TModel>  $query
     */
    private function assertOwned(?array $ids, Builder $query, ?string $brandId, string $field, string $message): void
    {
        if ($ids === null || $ids === []) {
            return;
        }
        $unique = array_values(array_unique($ids));
        $valid = array_filter($unique, fn ($id) => is_string($id) && Str::isUuid($id));
        $found = count($valid) === count($unique)
            ? $query->whereIn('id', $valid)->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))->count()
            : -1;
        if ($found !== count($unique)) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    public function canManage(User $actor, ?string $brandId): bool
    {
        return $brandId === null
            ? $actor->can('menu.manage') && $this->scope->brandIds($actor) === null
            : $this->scope->canManageBrand($actor, $brandId);
    }
}
