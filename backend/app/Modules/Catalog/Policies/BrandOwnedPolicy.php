<?php

namespace App\Modules\Catalog\Policies;

use App\Modules\Catalog\Application\MenuScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\WritableCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Kebijakan untuk data menu milik brand (kategori, item, grup modifier, promo).
 * Lihat: menu.view/menu.manage + cakupan brand (data tanpa brand, mis. promo seluruh company, terlihat oleh semua). Ubah: menu.manage + brand dipegang.
 */
abstract class BrandOwnedPolicy
{
    public function __construct(protected readonly MenuScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('menu.view') || $user->can('menu.manage');
    }

    public function view(User $user, Model $model): bool
    {
        $brandId = $model->getAttribute('brand_id');

        return $this->viewAny($user) && ($brandId === null || $this->scope->allowsBrand($user, $brandId));
    }

    public function create(User $user): bool
    {
        return $user->can('menu.manage') && WritableCompany::allows();
    }

    public function update(User $user, Model $model): bool
    {
        return $this->manages($user, $model->getAttribute('brand_id'));
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    public function manages(User $user, ?string $brandId): bool
    {
        if (! WritableCompany::allows()) {
            return false;
        }

        return $brandId === null
            ? $user->can('menu.manage') && $this->scope->brandIds($user) === null
            : $this->scope->canManageBrand($user, $brandId);
    }
}
