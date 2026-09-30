<?php

namespace App\Modules\Sales\Application;

use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;

/**
 * Nomor struk unik per perangkat per hari (FR-POS-24): {KODE_OUTLET}-{KODE_PERANGKAT}-{YYMMDD}-{URUT}.
 * Dibuat perangkat agar tetap berjalan offline; server hanya memeriksa format & keunikan.
 */
final class ReceiptNumber
{
    public static function make(Outlet $outlet, Device $device, CarbonImmutable $businessDate, int $sequence): string
    {
        return sprintf('%s-%s-%s-%04d', $outlet->code, $device->code, $businessDate->format('ymd'), $sequence);
    }

    /** Awalan nomor struk untuk satu perangkat pada satu hari bisnis, tanpa urutannya. */
    public static function prefix(Outlet $outlet, Device $device, CarbonImmutable $businessDate): string
    {
        return sprintf('%s-%s-%s-', $outlet->code, $device->code, $businessDate->format('ymd'));
    }

    public static function matches(string $receipt, Outlet $outlet, Device $device, CarbonImmutable $businessDate): bool
    {
        $prefix = self::prefix($outlet, $device, $businessDate);

        return str_starts_with($receipt, $prefix)
            && preg_match('/^\d{4,6}$/', substr($receipt, strlen($prefix))) === 1;
    }
}
