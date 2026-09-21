<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\Permission;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Tenancy\Application\TenantContext;
use Spatie\Permission\PermissionRegistrar;

/** Menyiapkan permission global dan role bawaan per company. */
class RoleProvisioner
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function syncPermissions(): void
    {
        $this->context->runAsSystem(function (): void {
            foreach (PermissionRegistry::PERMISSIONS as $group => $items) {
                foreach (array_keys($items) as $name) {
                    Permission::query()->updateOrCreate(
                        ['name' => $name, 'guard_name' => 'web'],
                        ['group' => $group],
                    );
                }
            }
        });
        $this->registrar->forgetCachedPermissions();
    }

    public function provisionCompany(string $companyId): void
    {
        $this->syncPermissions();

        $this->context->runAsSystem(function () use ($companyId): void {
            foreach (PermissionRegistry::defaultRoles() as $name => $def) {
                /** @var Role $role */
                $role = Role::query()->firstOrNew([
                    'company_id' => $companyId,
                    'name' => $name,
                    'guard_name' => 'web',
                ]);
                $role->forceFill([
                    'label' => $def['label'],
                    'is_system' => true,
                    'max_discount_percent' => $def['max_discount'],
                ])->save();

                $role->syncPermissions($def['permissions']);
            }
        });

        $this->registrar->forgetCachedPermissions();
    }
}
