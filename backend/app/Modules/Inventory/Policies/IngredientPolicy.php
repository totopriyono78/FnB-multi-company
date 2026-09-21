<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Domain\Models\Ingredient;

/**
 * Bahan baku adalah data master company (FR-INV-01).
 * Lihat: izin inventory, pembelian, atau pengelola menu (untuk menyusun resep). Ubah: inventory.manage tingkat company.
 */
class IngredientPolicy
{
    public function __construct(private readonly InventoryAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canView($user) || $this->access->canViewPurchasing($user) || $user->can('menu.manage');
    }

    public function view(User $user, Ingredient $ingredient): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->canManageMaster($user);
    }

    public function update(User $user, Ingredient $ingredient): bool
    {
        return $this->access->canManageMaster($user);
    }

    public function delete(User $user, Ingredient $ingredient): bool
    {
        return $this->access->canManageMaster($user);
    }
}
