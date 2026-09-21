<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Mencegah eskalasi hak akses: selain pemilik, user hanya boleh memberikan izin
 * kewenangan (PermissionRegistry::PRIVILEGED) yang dimilikinya sendiri (NFR-SEC-08).
 */
class GrantGuard
{
    public function isOwner(User $actor): bool
    {
        return $actor->hasRole('owner');
    }

    /**
     * @param  iterable<string>  $permissions
     */
    public function ensureCanGrantPermissions(User $actor, iterable $permissions): void
    {
        if ($this->isOwner($actor)) {
            return;
        }

        $mine = PermissionRegistry::expand($actor->getAllPermissions()->pluck('name'));
        $privileged = array_intersect(collect($permissions)->all(), PermissionRegistry::PRIVILEGED);
        $extra = array_values(array_diff($privileged, $mine));

        if ($extra !== []) {
            throw new AuthorizationException('Anda tidak dapat memberikan izin yang tidak Anda miliki: '.implode(', ', $extra).'.');
        }
    }

    /**
     * @param  Collection<int, Role>  $roles
     */
    public function ensureCanAssignRoles(User $actor, Collection $roles): void
    {
        if ($this->isOwner($actor)) {
            return;
        }

        $roles->loadMissing('permissions');
        $this->ensureCanGrantPermissions(
            $actor,
            $roles->flatMap(fn (Role $role) => $role->permissions->pluck('name'))->unique()->values(),
        );

        $myLimit = $this->maxDiscount($actor);
        foreach ($roles as $role) {
            if ((float) $role->max_discount_percent > $myLimit) {
                throw new AuthorizationException("Role {$role->label} memiliki batas diskon di atas batas Anda.");
            }
        }
    }

    public function maxDiscount(User $actor): float
    {
        return (float) $actor->roles->max(fn ($role) => (float) ($role instanceof Role ? $role->max_discount_percent : 0));
    }
}
