<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Hak akses & pembentukan filter laporan (ADR 0006).
 *
 * - `sales`: report.sales.company|brand|outlet (penjualan, pajak, anti-fraud, menu, laba kotor, dashboard).
 * - `inventory`: inventory.view (laporan inventory).
 */
class ReportAccess
{
    public const SALES = 'sales';

    public const INVENTORY = 'inventory';

    public function __construct(
        private readonly SalesAccess $sales,
        private readonly InventoryAccess $inventory,
        private readonly AccessScope $scope,
    ) {}

    public function can(User $user, string $kind): bool
    {
        return $kind === self::INVENTORY ? $this->inventory->canView($user) : $this->sales->canView($user);
    }

    /** @return list<string> */
    public function outletIds(User $user, string $kind): array
    {
        if (! $this->can($user, $kind)) {
            return [];
        }

        return $this->scope->outletIds($user);
    }

    /**
     * Outlet yang boleh dipilih di filter (aktif maupun sudah dinonaktifkan).
     *
     * @return array<string, string> id => nama
     */
    public function outletOptions(User $user, string $kind, ?string $brandId = null): array
    {
        $ids = $this->outletIds($user, $kind);

        /** @var array<string, string> $options */
        $options = Outlet::withTrashed()->whereIn('id', $ids)
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->orderBy('name')->pluck('name', 'id')->all();

        return $options;
    }

    /** @return array<string, string> id => nama */
    public function brandOptions(User $user, string $kind): array
    {
        $ids = $this->outletIds($user, $kind);

        /** @var array<string, string> $options */
        $options = Brand::withTrashed()
            ->whereIn('id', Outlet::withTrashed()->whereIn('id', $ids)->select('brand_id'))
            ->orderBy('name')->pluck('name', 'id')->all();

        return $options;
    }

    /**
     * @param  array{date_from?: string|null, date_to?: string|null, brand_id?: string|null, outlet_id?: string|null}  $input
     *
     * @throws ModelNotFoundException bila brand/outlet di luar cakupan (diperlakukan tidak ada)
     */
    public function filter(User $user, string $kind, array $input, ?CarbonImmutable $defaultTo = null): ReportFilter
    {
        $to = isset($input['date_to']) && $input['date_to'] !== '' ? CarbonImmutable::parse($input['date_to']) : ($defaultTo ?? self::today());
        $from = isset($input['date_from']) && $input['date_from'] !== '' ? CarbonImmutable::parse($input['date_from']) : $to->startOfMonth();
        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['date_to' => 'Tanggal akhir harus sama atau setelah tanggal awal.']);
        }
        if ($from->diffInDays($to) + 1 > ReportFilter::MAX_DAYS) {
            throw ValidationException::withMessages(['date_from' => 'Rentang laporan maksimal 1 tahun.']);
        }

        $ids = $this->outletIds($user, $kind);
        $labels = [];
        $brandId = $input['brand_id'] ?? null;
        $outletId = $input['outlet_id'] ?? null;

        if ($brandId !== null && $brandId !== '') {
            $brand = Brand::withTrashed()->find($brandId);
            $inScope = Outlet::withTrashed()->whereIn('id', $ids)->where('brand_id', $brandId)->pluck('id')->all();
            if ($brand === null || $inScope === []) {
                throw (new ModelNotFoundException)->setModel(Brand::class, [$brandId]);
            }
            $ids = array_values($inScope);
            $labels['Brand'] = $brand->name;
        } else {
            $brandId = null;
        }

        if ($outletId !== null && $outletId !== '') {
            $outlet = Outlet::withTrashed()->find($outletId);
            if ($outlet === null || ! in_array($outlet->id, $ids, true)) {
                throw (new ModelNotFoundException)->setModel(Outlet::class, [$outletId]);
            }
            $ids = [$outlet->id];
            $labels['Outlet'] = $outlet->name;
        } else {
            $outletId = null;
        }

        if ($brandId === null && $outletId === null) {
            $labels['Cakupan'] = $this->scope->isCompanyWide($user) ? 'Semua outlet' : count($ids).' outlet dalam cakupan Anda';
        }

        return new ReportFilter($from, $to, $ids, $brandId, $outletId, $labels);
    }

    /** Tanggal hari ini menurut zona waktu company aktif. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(self::timezone())->format('Y-m-d'));
    }

    public static function timezone(): string
    {
        $companyId = app(TenantContext::class)->companyId();
        $tz = $companyId === null ? null : Company::query()->whereKey($companyId)->value('timezone');

        return is_string($tz) && $tz !== '' ? $tz : (string) config('app.display_timezone', 'Asia/Jakarta');
    }
}
