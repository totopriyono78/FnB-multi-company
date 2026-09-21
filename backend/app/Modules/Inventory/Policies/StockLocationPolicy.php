<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Domain\Models\StockLocation;

/** Lokasi stok per outlet (FR-INV-02), mengikuti cakupan outlet user. */
class StockLocationPolicy
{
    public function __construct(private readonly InventoryAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canView($user) || $this->access->canViewPurchasing($user);
    }

    public function view(User $user, StockLocation $location): bool
    {
        return $this->viewAny($user) && $this->access->allowsOutlet($user, $location->outlet);
    }

    public function update(User $user, StockLocation $location): bool
    {
        return $this->access->canManageOutlet($user, $location->outlet);
    }
}
