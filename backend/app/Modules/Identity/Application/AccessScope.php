<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\RoleScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Database\Eloquent\Builder;

/**
 * Menentukan brand/outlet yang boleh diakses user di company aktif (FR-AUTH-06).
 * Tanpa baris role_scopes berarti akses seluruh company.
 */
class AccessScope
{
    /** @var array<string, array{brands: list<string>, outlets: list<string>}|null> */
    private array $cache = [];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{brands: list<string>, outlets: list<string>}|null null = seluruh company
     */
    public function for(User $user): ?array
    {
        $companyId = $this->context->requireCompanyId();
        $key = $companyId.':'.$user->id;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $membership = CompanyUser::query()->where('user_id', $user->id)->first();
        if ($membership === null) {
            return $this->cache[$key] = ['brands' => [], 'outlets' => []];
        }

        $scopes = RoleScope::query()->where('company_user_id', $membership->id)->get(['scope_type', 'scope_id']);
        if ($scopes->isEmpty()) {
            return $this->cache[$key] = null;
        }

        return $this->cache[$key] = [
            'brands' => $scopes->where('scope_type', RoleScope::BRAND)->pluck('scope_id')->values()->all(),
            'outlets' => $scopes->where('scope_type', RoleScope::OUTLET)->pluck('scope_id')->values()->all(),
        ];
    }

    /**
     * Muat cakupan banyak anggota sekaligus untuk menghindari N+1 query.
     *
     * @param  iterable<CompanyUser>  $members
     */
    public function prime(iterable $members): void
    {
        $companyId = $this->context->requireCompanyId();
        $byMember = [];
        foreach ($members as $member) {
            $byMember[$member->id] = $member->user_id;
        }
        if ($byMember === []) {
            return;
        }

        $scopes = RoleScope::query()->whereIn('company_user_id', array_keys($byMember))
            ->get(['company_user_id', 'scope_type', 'scope_id'])
            ->groupBy('company_user_id');

        foreach ($byMember as $memberId => $userId) {
            $rows = $scopes->get($memberId);
            $this->cache[$companyId.':'.$userId] = $rows === null ? null : [
                'brands' => $rows->where('scope_type', RoleScope::BRAND)->pluck('scope_id')->values()->all(),
                'outlets' => $rows->where('scope_type', RoleScope::OUTLET)->pluck('scope_id')->values()->all(),
            ];
        }
    }

    public function isCompanyWide(User $user): bool
    {
        return $this->for($user) === null;
    }

    public function allowsBrand(User $user, string $brandId): bool
    {
        $scope = $this->for($user);
        if ($scope === null) {
            return true;
        }

        if (in_array($brandId, $scope['brands'], true)) {
            return true;
        }

        // Outlet manager boleh melihat brand dari outlet yang dipegangnya.
        return $scope['outlets'] !== []
            && Outlet::query()->whereIn('id', $scope['outlets'])->where('brand_id', $brandId)->exists();
    }

    public function allowsOutlet(User $user, Outlet $outlet): bool
    {
        $scope = $this->for($user);

        return $scope === null
            || in_array($outlet->id, $scope['outlets'], true)
            || in_array($outlet->brand_id, $scope['brands'], true);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyToOutletQuery(Builder $query, User $user, string $outletColumn = 'id', string $brandColumn = 'brand_id'): Builder
    {
        $scope = $this->for($user);
        if ($scope === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($scope, $outletColumn, $brandColumn): void {
            $q->whereIn($outletColumn, $scope['outlets'])->orWhereIn($brandColumn, $scope['brands']);
        });
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
