<?php

namespace App\Modules\Tenancy\Policies;

use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\WritableCompany;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;

class DevicePolicy
{
    public function __construct(private readonly AccessScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('device.view') || $user->can('device.manage');
    }

    public function view(User $user, Device $device): bool
    {
        return $this->viewAny($user) && $this->scope->allowsOutlet($user, $device->outlet);
    }

    public function create(User $user, ?Outlet $outlet = null): bool
    {
        return $user->can('device.manage') && ($outlet === null || $this->scope->allowsOutlet($user, $outlet)) && WritableCompany::allows();
    }

    public function update(User $user, Device $device): bool
    {
        return $user->can('device.manage') && $this->scope->allowsOutlet($user, $device->outlet) && WritableCompany::allows();
    }
}
