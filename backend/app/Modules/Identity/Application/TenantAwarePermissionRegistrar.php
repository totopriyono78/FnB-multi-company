<?php

namespace App\Modules\Identity\Application;

use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cache permission spatie harus dibangun dari seluruh role (lintas company).
 * Tanpa ini, cache yang terbentuk saat RLS aktif hanya berisi role company tersebut
 * dan membuat pengecekan izin company lain gagal.
 */
class TenantAwarePermissionRegistrar extends PermissionRegistrar
{
    /** @return Collection<int, Model> */
    protected function getPermissionsWithRoles(): Collection
    {
        return app(TenantContext::class)->runAsSystem(fn () => parent::getPermissionsWithRoles());
    }
}
