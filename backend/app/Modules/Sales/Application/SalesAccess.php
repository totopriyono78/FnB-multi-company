<?php

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Auth\Access\AuthorizationException;

/** Siapa boleh melihat transaksi di back-office (report.sales.* + cakupan outlet/brand). */
class SalesAccess
{
    public const VIEW_PERMISSIONS = ['report.sales.company', 'report.sales.brand', 'report.sales.outlet'];

    public function __construct(private readonly AccessScope $scope) {}

    public function canView(User $user): bool
    {
        foreach (self::VIEW_PERMISSIONS as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function outletIds(User $user): array
    {
        if (! $this->canView($user)) {
            throw new AuthorizationException('Anda tidak memiliki akses ke data transaksi.');
        }

        return $this->scope->outletIds($user);
    }

    public function assertOutlet(User $user, Outlet $outlet): void
    {
        if (! $this->canView($user) || ! $this->scope->allowsOutlet($user, $outlet)) {
            throw new AuthorizationException('Anda tidak memiliki akses ke outlet ini.');
        }
    }
}
