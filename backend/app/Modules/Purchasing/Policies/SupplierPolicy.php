<?php

namespace App\Modules\Purchasing\Policies;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Purchasing\Domain\Models\Supplier;

/** Pemasok: lihat oleh pemegang izin pembelian/inventory, ubah oleh purchasing.manage. */
class SupplierPolicy
{
    public function __construct(private readonly InventoryAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canViewPurchasing($user) || $this->access->canView($user);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->canManageSuppliers($user);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->access->canManageSuppliers($user);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->access->canManageSuppliers($user);
    }
}
