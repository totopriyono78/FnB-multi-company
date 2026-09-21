<?php

namespace App\Modules\Audit\Policies;

use App\Modules\Identity\Domain\Models\User;

/** Audit log hanya bisa dilihat; tidak ada create/update/delete (FR-AUD-01). */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit.view');
    }

    public function view(User $user): bool
    {
        return $user->can('audit.view');
    }

    public function create(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(): bool
    {
        return false;
    }

    public function deleteAny(): bool
    {
        return false;
    }
}
