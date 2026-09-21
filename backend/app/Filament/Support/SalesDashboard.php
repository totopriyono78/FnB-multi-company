<?php

namespace App\Filament\Support;

use App\Modules\Reporting\Application\DashboardReport;
use App\Modules\Reporting\Application\ReportAccess;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;

/**
 * Data dashboard penjualan untuk widget Ringkasan (FR-RPT-01). Disimpan 30 detik per user & filter agar
 * beberapa widget di satu halaman tidak menghitung ulang.
 */
final class SalesDashboard
{
    public const CACHE_SECONDS = 30;

    public static function canView(): bool
    {
        $user = ReportPage::user();
        $access = app(ReportAccess::class);

        return $user !== null && $access->can($user, ReportAccess::SALES) && $access->outletIds($user, ReportAccess::SALES) !== [];
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array<string, mixed>|null
     */
    public static function data(?array $filters): ?array
    {
        $user = ReportPage::user();
        if ($user === null || ! self::canView()) {
            return null;
        }
        $input = [
            'brand_id' => is_string($filters['brand_id'] ?? null) ? $filters['brand_id'] : null,
            'outlet_id' => is_string($filters['outlet_id'] ?? null) ? $filters['outlet_id'] : null,
        ];
        $company = app(TenantContext::class)->requireCompanyId();
        $key = 'fnb.dashboard.'.$company.'.'.$user->id.'.'.md5((string) json_encode($input));

        return Cache::remember($key, self::CACHE_SECONDS, function () use ($user, $input): array {
            try {
                $filter = app(ReportAccess::class)->filter($user, ReportAccess::SALES, $input);
            } catch (ModelNotFoundException) {
                $filter = app(ReportAccess::class)->filter($user, ReportAccess::SALES, []);
            }

            return app(DashboardReport::class)->build($filter);
        });
    }
}
