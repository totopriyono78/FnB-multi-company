<?php

namespace App\Modules\Tenancy\Policies;

use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\WritableCompany;
use App\Modules\Tenancy\Domain\Models\Brand;

class BrandPolicy
{
    public function __construct(private readonly AccessScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('brand.view') || $user->can('brand.manage');
    }

    public function view(User $user, Brand $brand): bool
    {
        return $this->viewAny($user) && $this->scope->allowsBrand($user, $brand->id);
    }

    public function create(User $user): bool
    {
        return $user->can('brand.manage') && $this->scope->isCompanyWide($user) && WritableCompany::allows();
    }

    public function update(User $user, Brand $brand): bool
    {
        return $user->can('brand.manage') && $this->scope->allowsBrand($user, $brand->id) && WritableCompany::allows();
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $this->create($user);
    }
}
