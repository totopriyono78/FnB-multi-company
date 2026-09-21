<?php

namespace App\Modules;

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Audit\Policies\AuditLogPolicy;
use App\Modules\Catalog\Application\PriceHistoryRecorder;
use App\Modules\Catalog\Console\ProvisionCatalog;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\KitchenStation;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use App\Modules\Catalog\Domain\Models\Promotion;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Catalog\Listeners\ProvisionCatalogDefaults;
use App\Modules\Catalog\Policies\CatalogSettingsPolicy;
use App\Modules\Catalog\Policies\ItemPolicy;
use App\Modules\Catalog\Policies\MenuCategoryPolicy;
use App\Modules\Catalog\Policies\ModifierGroupPolicy;
use App\Modules\Catalog\Policies\PromotionPolicy;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Application\TenantAwarePermissionRegistrar;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\PersonalAccessToken;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Listeners\ProvisionDefaultRoles;
use App\Modules\Identity\Policies\CompanyUserPolicy;
use App\Modules\Inventory\Application\StockLocations;
use App\Modules\Inventory\Console\PostSalesStock as PostSalesStockCommand;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Inventory\Listeners\PostSalesStock;
use App\Modules\Inventory\Policies\IngredientPolicy;
use App\Modules\Inventory\Policies\StockLocationPolicy;
use App\Modules\Payment\Application\PaymentMethods;
use App\Modules\Payment\Console\SandboxPay;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Purchasing\Policies\SupplierPolicy;
use App\Modules\Reporting\Console\SendScheduledReports;
use App\Modules\Shared\Infrastructure\Console\EnsurePartitions;
use App\Modules\Sync\Application\SyncVersions;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Events\CompanyRegistered;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use App\Modules\Tenancy\Policies\BrandPolicy;
use App\Modules\Tenancy\Policies\CompanyPolicy;
use App\Modules\Tenancy\Policies\DevicePolicy;
use App\Modules\Tenancy\Policies\OutletPolicy;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/** Registrasi lintas modul: policy, event, rate limit, dan morph map. */
class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(AccessScope::class);
        // spatie mendaftarkan registrar di boot(); timpa setelah semua provider selesai boot.
        $this->app->booted(function (): void {
            $this->app->forgetInstance(PermissionRegistrar::class);
            $this->app->singleton(
                PermissionRegistrar::class,
                TenantAwarePermissionRegistrar::class,
            );
        });
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Relation::enforceMorphMap([
            'user' => User::class,
            'device' => Device::class,
        ]);

        Model::shouldBeStrict(! $this->app->isProduction());

        Gate::policy(Brand::class, BrandPolicy::class);
        Gate::policy(Outlet::class, OutletPolicy::class);
        Gate::policy(Device::class, DevicePolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(CompanyUser::class, CompanyUserPolicy::class);
        Gate::define('platform-admin', fn (User $user) => $user->is_platform_admin);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        Gate::policy(MenuCategory::class, MenuCategoryPolicy::class);
        Gate::policy(Item::class, ItemPolicy::class);
        Gate::policy(ModifierGroup::class, ModifierGroupPolicy::class);
        Gate::policy(Promotion::class, PromotionPolicy::class);
        Gate::policy(KitchenStation::class, CatalogSettingsPolicy::class);
        Gate::policy(SalesChannel::class, CatalogSettingsPolicy::class);
        Gate::policy(Ingredient::class, IngredientPolicy::class);
        Gate::policy(StockLocation::class, StockLocationPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        $this->app->make(PriceHistoryRecorder::class)->register();
        $this->app->make(SyncVersions::class)->register();
        Event::listen('eloquent.created: '.Outlet::class, fn (Outlet $outlet) => $this->app->make(PaymentMethods::class)->ensure($outlet));
        Event::listen('eloquent.created: '.Outlet::class, fn (Outlet $outlet) => $this->app->make(StockLocations::class)->ensureDefault($outlet));
        Event::subscribe(PostSalesStock::class);

        // Koneksi dibuat ulang / transaksi dibatalkan dapat mengubah role PostgreSQL diam-diam: terapkan ulang konteks tenant.
        $resync = function (Connection $connection): void {
            if ($connection->getDriverName() !== 'pgsql'
                || $connection->getName() !== DB::getDefaultConnection()
                || ! $this->app->resolved(TenantContext::class)) {
                return;
            }
            $this->app->make(TenantContext::class)->resyncSafely();
        };
        Event::listen(ConnectionEstablished::class, fn (ConnectionEstablished $e) => $resync($e->connection));
        Event::listen(TransactionRolledBack::class, fn (TransactionRolledBack $e) => $resync($e->connection));

        Event::listen(CompanyRegistered::class, ProvisionDefaultRoles::class);
        Event::listen(CompanyRegistered::class, ProvisionCatalogDefaults::class);
        Event::listen(Login::class, function (Login $event): void {
            if ($event->guard === 'web' && app()->bound('session.store') && request()->hasSession()) {
                request()->session()->put('fnb_auth_at', now()->getTimestamp());
            }
        });

        foreach (['brand', 'outlet', 'device', 'member', 'role', 'company', 'invitation', 'category', 'item', 'modifier_group', 'modifierGroup', 'promotion', 'station', 'channel', 'shift', 'order', 'intent',
            'ingredient', 'location', 'adjustment', 'transfer', 'count', 'supplier', 'purchaseOrder', 'receipt', 'line', 'schedule'] as $param) {
            Route::pattern($param, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
        }

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('login:'.$request->ip()),
            Limit::perMinute(5)->by('login:'.mb_strtolower((string) $request->input('login', $request->input('email', ''))).'|'.$request->ip()),
        ]);
        RateLimiter::for('pin', fn (Request $request) => Limit::perMinute(20)->by('pin:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('sync', fn (Request $request) => Limit::perMinute(120)->by('sync:'.($request->attributes->get('device_id') ?? $request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(600)->by('webhook:'.$request->ip()));
        // Laporan & ekspor relatif berat: dibatasi per user.
        RateLimiter::for('reports', fn (Request $request) => Limit::perMinute(30)->by('reports:'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->getKey() ?? $request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([EnsurePartitions::class, ProvisionCatalog::class, SandboxPay::class, PostSalesStockCommand::class, SendScheduledReports::class]);
        }
    }
}
