<?php

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\RoleScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\WritableCompany;

class CompanyUserPolicy
{
    public function __construct(private readonly AccessScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('user.manage') || $user->can('user.manage_outlet');
    }

    public function view(User $user, CompanyUser $member): bool
    {
        if ($user->can('user.manage') || $member->user_id === $user->id) {
            return true;
        }

        if (! $user->can('user.manage_outlet')) {
            return false;
        }

        $mine = $this->scope->for($user);
        if ($mine === null) {
            return true;
        }

        return RoleScope::query()
            ->where('company_user_id', $member->id)
            ->where('scope_type', RoleScope::OUTLET)
            ->whereIn('scope_id', $mine['outlets'])
            ->exists();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && WritableCompany::allows();
    }

    public function update(User $user, CompanyUser $member): bool
    {
        return $this->view($user, $member) && $this->viewAny($user) && WritableCompany::allows();
    }
}
