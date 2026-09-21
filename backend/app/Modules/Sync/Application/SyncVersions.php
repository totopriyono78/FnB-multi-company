<?php

namespace App\Modules\Sync\Application;

use App\Modules\Catalog\Domain\Events\ItemAvailabilityChanged;
use App\Modules\Catalog\Domain\Events\MenuChanged;
use App\Modules\Catalog\Domain\Models\BundleGroup;
use App\Modules\Catalog\Domain\Models\BundleGroupOption;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemPrice;
use App\Modules\Catalog\Domain\Models\ItemVariant;
use App\Modules\Catalog\Domain\Models\KitchenStation;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\Modifier;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use App\Modules\Catalog\Domain\Models\OutletItemAvailability;
use App\Modules\Catalog\Domain\Models\Promotion;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Payment\Domain\Models\OutletPaymentMethod;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Nomor versi data master per company (ADR 0004). Naik setiap ada perubahan yang perlu ditarik POS;
 * perangkat membandingkan versinya pada `GET /sync/pull` dan heartbeat.
 */
class SyncVersions
{
    /** Model yang perubahannya memengaruhi snapshot POS. */
    private const WATCHED = [
        Item::class, ItemVariant::class, ItemPrice::class, MenuCategory::class, Modifier::class, ModifierGroup::class,
        BundleGroup::class, BundleGroupOption::class, OutletItemAvailability::class, Promotion::class,
        SalesChannel::class, KitchenStation::class, Outlet::class, Brand::class, CompanyUser::class, Role::class,
        OutletPaymentMethod::class,
    ];

    public function __construct(private readonly TenantContext $context) {}

    public function register(): void
    {
        foreach (self::WATCHED as $class) {
            foreach (['saved', 'deleted'] as $event) {
                Event::listen("eloquent.{$event}: {$class}", fn (Model $model) => $this->bumpFor($model));
            }
        }
        Event::listen('eloquent.saved: '.Company::class, function (Company $company): void {
            if ($company->wasChanged(['status', 'settings', 'name'])) {
                $this->bump((string) $company->getKey());
            }
        });
        Event::listen(MenuChanged::class, fn (MenuChanged $e) => $this->bump($e->companyId));
        Event::listen(ItemAvailabilityChanged::class, fn (ItemAvailabilityChanged $e) => $this->bump($e->companyId));
    }

    public function bump(string $companyId): int
    {
        $run = fn (): int => (int) DB::selectOne(
            'INSERT INTO sync_versions (company_id, version, updated_at) VALUES (?, 2, now())
             ON CONFLICT (company_id) DO UPDATE SET version = sync_versions.version + 1, updated_at = now()
             RETURNING version',
            [$companyId],
        )->version;

        return $this->inCompany($companyId, $run);
    }

    public function current(string $companyId): int
    {
        return $this->inCompany($companyId, fn (): int => (int) (DB::table('sync_versions')->where('company_id', $companyId)->value('version') ?? 1));
    }

    private function bumpFor(Model $model): void
    {
        $companyId = $model->getAttribute('company_id');
        if (is_string($companyId) && $companyId !== '') {
            $this->bump($companyId);
        }
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function inCompany(string $companyId, \Closure $callback): mixed
    {
        if ($this->context->companyId() === $companyId) {
            return $callback();
        }

        // Perubahan dari proses sistem (seeder, provisioning) atau role global.
        return $this->context->runAsSystem($callback);
    }
}
