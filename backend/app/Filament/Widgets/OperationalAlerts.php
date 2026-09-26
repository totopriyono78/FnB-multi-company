<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Resources\StockBalanceResource;
use App\Filament\Resources\StockCountResource;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\SalesLabels;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\RoleScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Domain\Models\StockCount;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Purchasing\Domain\Models\PurchaseOrder;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Tenancy\Domain\DeviceStatus;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Hanya angka yang memicu tindakan: perangkat offline, data belum tersinkron, PIN terkunci.
 * Transaksi yang ditandai server (harga/promo/otorisasi offline) ditampilkan untuk ditinjau.
 */
class OperationalAlerts extends StatsOverviewWidget
{
    /** Dirender bersama halaman: satu request, bukan satu request per widget (lebih ringan di server satu proses). */
    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $cutoff = now()->subSeconds((int) config('fnb.devices.offline_after_seconds'));
        $user = auth()->user();
        $scope = app(AccessScope::class);
        $outletQuery = Outlet::query()->where('is_active', true);
        $active = Device::query()->where('status', DeviceStatus::Active->value);
        $lockedQuery = CompanyUser::query()->where('pin_locked_until', '>', now());

        // Angka mengikuti cakupan user (FR-AUTH-06).
        $mine = $user instanceof User ? $scope->for($user) : ['brands' => [], 'outlets' => []];
        if ($mine !== null) {
            $scope->applyToOutletQuery($outletQuery, $user);
            $outletIds = (clone $outletQuery)->pluck('id');
            $active->whereIn('outlet_id', $outletIds);
            $lockedQuery->whereHas('scopes', fn ($q) => $q->where('scope_type', RoleScope::OUTLET)->whereIn('scope_id', $outletIds));
        }

        $offline = (clone $active)->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff))->count();
        $pending = (int) (clone $active)->sum('pending_sync_count');
        $lockedPins = $lockedQuery->count();
        $outlets = $outletQuery->count();

        $stats = [
            Stat::make('Outlet aktif', number_format($outlets, 0, ',', '.'))->icon('heroicon-o-building-storefront'),
            Stat::make('Perangkat offline', number_format($offline, 0, ',', '.'))->icon('heroicon-o-signal-slash')
                ->description($offline > 0 ? 'Cek koneksi internet di outlet' : 'Semua perangkat terhubung')
                ->color($offline > 0 ? 'danger' : 'success'),
            Stat::make('Transaksi belum tersinkron', number_format($pending, 0, ',', '.'))->icon('heroicon-o-arrow-path')
                ->description($pending > 0 ? 'Terkirim otomatis saat perangkat online' : 'Tidak ada antrean')
                ->color($pending > 0 ? 'warning' : 'success'),
            Stat::make('PIN terkunci', number_format($lockedPins, 0, ',', '.'))->icon('heroicon-o-lock-closed')
                ->description($lockedPins > 0 ? 'Buka kunci di menu Staf' : 'Tidak ada')
                ->color($lockedPins > 0 ? 'warning' : 'success'),
        ];

        if (SalesLabels::canView()) {
            $flagged = Order::query()
                ->whereIn('outlet_id', SalesLabels::outletIds())
                ->where('business_date', '>=', now()->subDays(7)->format('Y-m-d'))
                ->whereRaw("flags <> '[]'::jsonb")
                ->count();
            $stats[] = Stat::make('Transaksi perlu ditinjau', number_format($flagged, 0, ',', '.'))->icon('heroicon-o-flag')
                ->description($flagged > 0 ? '7 hari terakhir · lihat menu Transaksi' : 'Tidak ada dalam 7 hari terakhir')
                ->color($flagged > 0 ? 'warning' : 'success')
                ->url($flagged > 0 ? OrderResource::getUrl('index', ['tableFilters' => ['flagged' => ['value' => '1']]]) : null);
        }

        $stats = [...$stats, ...$this->inventoryStats()];

        return array_map(self::tint(...), $stats);
    }

    /** Empat kartu per baris di layar lebar agar 8 kartu tersusun dua baris penuh, bukan 3-3-2. */
    protected function getColumns(): int
    {
        return count($this->getCachedStats()) >= 4 ? 4 : 3;
    }

    /** Lingkaran ikon mengikuti warna status kartu (avatar bernuansa ala Vuexy). */
    private static function tint(Stat $stat): Stat
    {
        $color = $stat->getDescriptionColor();

        return $stat->extraAttributes(['class' => 'fnb-stat--'.(is_string($color) ? $color : 'primary')]);
    }

    /**
     * Stok kritis & dokumen yang menunggu persetujuan (FR-INV-08).
     *
     * @return list<Stat>
     */
    private function inventoryStats(): array
    {
        $user = InventoryFields::user();
        if ($user === null) {
            return [];
        }
        $stats = [];
        $access = InventoryFields::access();
        if ($access->canView($user)) {
            $outletIds = $access->outletIds($user);
            $critical = StockBalance::query()
                ->join('ingredients', 'ingredients.id', '=', 'stock_balances.ingredient_id')
                ->whereIn('stock_balances.location_id', StockLocation::query()->whereIn('outlet_id', $outletIds)->where('is_active', true)->select('id'))
                ->where('ingredients.is_active', true)
                ->whereRaw('(stock_balances.qty < 0 OR (COALESCE(stock_balances.min_qty, ingredients.min_stock) > 0 AND stock_balances.qty < COALESCE(stock_balances.min_qty, ingredients.min_stock)))')
                ->count();
            $stats[] = Stat::make('Stok kritis', number_format($critical, 0, ',', '.'))->icon('heroicon-o-archive-box-x-mark')
                ->description($critical > 0 ? 'Di bawah minimum atau minus · pesan ulang' : 'Semua bahan di atas minimum')
                ->color($critical > 0 ? 'danger' : 'success')
                ->url($critical > 0 ? StockBalanceResource::getUrl('index', ['tableFilters' => ['low' => ['isActive' => true]]]) : null);

            $approvable = $user->can('inventory.approve_count') ? $outletIds : [];
            $counts = $approvable === [] ? 0 : StockCount::query()->whereIn('outlet_id', $approvable)->where('status', StockCount::SUBMITTED)->count();
            if ($counts > 0) {
                $stats[] = Stat::make('Opname menunggu persetujuan', number_format($counts, 0, ',', '.'))->icon('heroicon-o-clipboard-document-check')
                    ->description('Setujui agar stok disesuaikan')->color('warning')
                    ->url(StockCountResource::getUrl('index', ['tableFilters' => ['status' => ['value' => StockCount::SUBMITTED]]]));
            }
        }
        if ($user->can('purchasing.approve')) {
            $pos = PurchaseOrder::query()->whereIn('outlet_id', $access->outletIds($user, true))->where('status', PurchaseOrder::SUBMITTED)->count();
            if ($pos > 0) {
                $stats[] = Stat::make('PO menunggu persetujuan', number_format($pos, 0, ',', '.'))->icon('heroicon-o-document-text')
                    ->description('Tinjau sebelum pemasok mengirim')->color('warning')
                    ->url(PurchaseOrderResource::getUrl('index', ['tableFilters' => ['status' => ['values' => [PurchaseOrder::SUBMITTED]]]]));
            }
        }

        return $stats;
    }
}
