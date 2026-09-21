<?php

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Application\RoleProvisioner;
use App\Modules\Tenancy\Domain\Events\CompanyRegistered;

class ProvisionDefaultRoles
{
    public function __construct(private readonly RoleProvisioner $provisioner) {}

    public function handle(CompanyRegistered $event): void
    {
        $this->provisioner->provisionCompany($event->companyId);
    }
}
