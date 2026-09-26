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

    /**
     * Outlet dalam cakupan user (termasuk yang dinonaktifkan/dihapus), diurutkan menurut nama.
     * Dipakai berulang kali dalam satu request — oleh pengecekan akses tiap menu navigasi,
     * filter laporan, dan halaman — sehingga cukup dimuat sekali (lihat flush()).
     *
     * @var array<string, list<array{id: string, name: string, code: string, brand_id: string, active: bool}>>
     */
    private array $outletCache = [];

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

    /**
     * @return list<array{id: string, name: string, code: string, brand_id: string, active: bool}>
     */
    public function outlets(User $user): array
    {
        $key = $this->context->requireCompanyId().':'.$user->id;

        return $this->outletCache[$key] ??= $this->applyToOutletQuery(Outlet::withTrashed(), $user)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'brand_id', 'is_active', 'deleted_at'])
            ->map(fn (Outlet $o): array => [
                'id' => (string) $o->id,
                'name' => (string) $o->name,
                'code' => (string) $o->code,
                'brand_id' => (string) $o->brand_id,
                'active' => (bool) $o->is_active && $o->deleted_at === null,
            ])
            ->values()
            ->all();
    }

    /**
     * ID seluruh outlet dalam cakupan (termasuk yang dinonaktifkan), urut nama.
     *
     * @return list<string>
     */
    public function outletIds(User $user): array
    {
        return array_column($this->outlets($user), 'id');
    }

    /**
     * Pilihan outlet aktif dalam cakupan: id => "Nama (KODE)", urut nama.
     *
     * @return array<string, string>
     */
    public function activeOutletOptions(User $user, ?string $brandId = null): array
    {
        $options = [];
        foreach ($this->outlets($user) as $outlet) {
            if ($outlet['active'] && ($brandId === null || $outlet['brand_id'] === $brandId)) {
                $options[$outlet['id']] = "{$outlet['name']} ({$outlet['code']})";
            }
        }

        return $options;
    }

    public function flush(): void
    {
        $this->cache = [];
        $this->outletCache = [];
    }
}
