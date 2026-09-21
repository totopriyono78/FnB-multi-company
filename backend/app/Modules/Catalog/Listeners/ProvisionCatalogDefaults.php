<?php

namespace App\Modules\Catalog\Listeners;

use App\Modules\Catalog\Application\CatalogProvisioner;
use App\Modules\Tenancy\Domain\Events\CompanyRegistered;

class ProvisionCatalogDefaults
{
    public function __construct(private readonly CatalogProvisioner $provisioner) {}

    public function handle(CompanyRegistered $event): void
    {
        $this->provisioner->provision($event->companyId);
    }
}
