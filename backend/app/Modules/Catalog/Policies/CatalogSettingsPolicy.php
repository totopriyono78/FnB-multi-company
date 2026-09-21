<?php

namespace App\Modules\Catalog\Policies;

use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\WritableCompany;

/** Stasiun dapur & channel penjualan berlaku untuk seluruh company. */
class CatalogSettingsPolicy
{
    public function __construct(private readonly AccessScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('menu.view') || $user->can('menu.manage');
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('menu.manage') && $this->scope->isCompanyWide($user) && WritableCompany::allows();
    }

    public function update(User $user): bool
    {
        return $this->create($user);
    }

    public function delete(User $user): bool
    {
        return $this->create($user);
    }
}
