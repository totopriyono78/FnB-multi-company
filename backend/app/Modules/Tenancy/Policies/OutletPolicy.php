<?php

namespace App\Modules\Tenancy\Policies;

use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\WritableCompany;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;

class OutletPolicy
{
    public function __construct(private readonly AccessScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('outlet.view') || $user->can('outlet.manage');
    }

    public function view(User $user, Outlet $outlet): bool
    {
        return $this->viewAny($user) && $this->scope->allowsOutlet($user, $outlet);
    }

    public function create(User $user, ?Brand $brand = null): bool
    {
        if (! $user->can('outlet.manage') || ! WritableCompany::allows()) {
            return false;
        }

        $scope = $this->scope->for($user);

        return $scope === null || ($brand !== null && in_array($brand->id, $scope['brands'], true));
    }

    public function update(User $user, Outlet $outlet): bool
    {
        return $user->can('outlet.manage') && $this->scope->allowsOutlet($user, $outlet) && WritableCompany::allows();
    }

    public function delete(User $user, Outlet $outlet): bool
    {
        return $this->update($user, $outlet);
    }
}
