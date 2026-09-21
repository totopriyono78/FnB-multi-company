<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Domain\Models\Company;

/**
 * Pemeriksaan mode baca-saja langganan (FR-TEN-08) untuk dipakai di policy,
 * sehingga back-office Filament mengikuti aturan yang sama dengan API.
 */
final class WritableCompany
{
    public static function allows(): bool
    {
        $companyId = app(TenantContext::class)->companyId();
        if ($companyId === null) {
            return false;
        }

        $key = 'fnb.writable.'.$companyId;
        $attributes = request()->attributes;
        if (! $attributes->has($key)) {
            $attributes->set($key, ! (Company::query()->find($companyId)?->isReadOnly() ?? true));
        }

        return (bool) $attributes->get($key);
    }
}
