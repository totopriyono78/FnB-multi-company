<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Database\Eloquent\Builder;

/** Brand yang boleh diakses user untuk data menu (FR-AUTH-06). */
class MenuScope
{
    public function __construct(private readonly AccessScope $scope) {}

    /** @return list<string>|null null = semua brand */
    public function brandIds(User $user): ?array
    {
        $scope = $this->scope->for($user);
        if ($scope === null) {
            return null;
        }

        $fromOutlets = $scope['outlets'] === []
            ? []
            : Outlet::query()->whereIn('id', $scope['outlets'])->pluck('brand_id')->all();

        return array_values(array_unique(array_merge($scope['brands'], $fromOutlets)));
    }

    public function allowsBrand(User $user, string $brandId): bool
    {
        $ids = $this->brandIds($user);

        return $ids === null || in_array($brandId, $ids, true);
    }

    /**
     * Hanya pengguna tingkat company atau pemegang brand yang boleh mengubah menu brand.
     * Pengguna yang dibatasi per outlet hanya melihat.
     */
    public function canManageBrand(User $user, string $brandId): bool
    {
        if (! $user->can('menu.manage')) {
            return false;
        }
        $scope = $this->scope->for($user);

        return $scope === null || in_array($brandId, $scope['brands'], true);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, User $user, string $column = 'brand_id'): Builder
    {
        $ids = $this->brandIds($user);

        return $ids === null ? $query : $query->whereIn($column, $ids);
    }
}
