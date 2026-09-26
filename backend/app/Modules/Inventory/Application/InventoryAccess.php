<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\WritableCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Hak akses inventory & pembelian sesuai matriks SRS §12.1 + cakupan outlet (FR-AUTH-06).
 *
 * - Lihat: inventory.view / inventory.manage (purchasing.* untuk pembelian) pada outlet dalam cakupan.
 * - Data master (bahan, sub-resep) hanya diubah pengguna tingkat company.
 */
class InventoryAccess
{
    public function __construct(private readonly AccessScope $scope) {}

    public function canView(User $user): bool
    {
        return $user->can('inventory.view') || $user->can('inventory.manage');
    }

    public function canViewPurchasing(User $user): bool
    {
        return $user->can('purchasing.view') || $user->can('purchasing.manage') || $user->can('purchasing.request') || $user->can('purchasing.approve');
    }

    public function companyWide(User $user): bool
    {
        return $this->scope->isCompanyWide($user);
    }

    /** Kelola data master bahan (tingkat company). */
    public function canManageMaster(User $user): bool
    {
        return WritableCompany::allows() && $user->can('inventory.manage') && $this->companyWide($user);
    }

    public function canManageOutlet(User $user, Outlet $outlet): bool
    {
        return WritableCompany::allows() && $user->can('inventory.manage') && $this->scope->allowsOutlet($user, $outlet);
    }

    public function canApproveCount(User $user, Outlet $outlet): bool
    {
        return WritableCompany::allows() && $user->can('inventory.approve_count') && $this->scope->allowsOutlet($user, $outlet);
    }

    public function canRequestPurchase(User $user, Outlet $outlet): bool
    {
        return WritableCompany::allows()
            && ($user->can('purchasing.manage') || $user->can('purchasing.request'))
            && $this->scope->allowsOutlet($user, $outlet);
    }

    public function canManagePurchase(User $user, Outlet $outlet): bool
    {
        return WritableCompany::allows() && $user->can('purchasing.manage') && $this->scope->allowsOutlet($user, $outlet);
    }

    public function canApprovePurchase(User $user, Outlet $outlet): bool
    {
        return WritableCompany::allows() && $user->can('purchasing.approve') && $this->scope->allowsOutlet($user, $outlet);
    }

    /** Penerimaan barang: petugas pembelian atau pengelola stok outlet. */
    public function canReceive(User $user, Outlet $outlet): bool
    {
        return $this->canManagePurchase($user, $outlet) || $this->canManageOutlet($user, $outlet);
    }

    public function canManageSuppliers(User $user): bool
    {
        return WritableCompany::allows() && $user->can('purchasing.manage');
    }

    /**
     * Outlet yang boleh dilihat user untuk inventory (atau pembelian).
     *
     * @return list<string>
     */
    public function outletIds(User $user, bool $purchasing = false): array
    {
        $allowed = $purchasing ? $this->canViewPurchasing($user) : $this->canView($user);
        if (! $allowed) {
            throw new AuthorizationException($purchasing ? 'Anda tidak memiliki akses ke data pembelian.' : 'Anda tidak memiliki akses ke data inventory.');
        }

        return $this->scope->outletIds($user);
    }

    /**
     * Tanggal bisnis "hari ini" untuk filter bawaan laporan: memakai zona waktu Indonesia paling timur (WIT)
     * agar transaksi hari ini di outlet WIB/WITA/WIT selalu tercakup.
     */
    public static function today(): string
    {
        return now()->setTimezone('Asia/Jayapura')->format('Y-m-d');
    }

    public function allowsOutlet(User $user, Outlet $outlet): bool
    {
        return $this->scope->allowsOutlet($user, $outlet);
    }
}
