<?php

namespace App\Modules\Tenancy\Policies;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Application\WritableCompany;
use App\Modules\Tenancy\Domain\Models\Company;

class CompanyPolicy
{
    public function view(User $user, Company $company): bool
    {
        return $this->isCurrent($company) && ($user->can('company.view') || $user->can('company.manage'));
    }

    public function update(User $user, Company $company): bool
    {
        return $this->isCurrent($company) && $user->can('company.manage') && WritableCompany::allows();
    }

    private function isCurrent(Company $company): bool
    {
        return app(TenantContext::class)->companyId() === $company->id;
    }
}
