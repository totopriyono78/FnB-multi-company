<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Domain\Models\BusinessDay;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;

/** Hari bisnis mengikuti jam pergantian outlet, bukan tanggal kalender (BR-20). */
class BusinessCalendar
{
    public function businessDate(Outlet $outlet, CarbonImmutable $at): CarbonImmutable
    {
        $local = $at->setTimezone($outlet->timezone);
        $cutoff = substr((string) ($outlet->business_day_cutoff ?: '00:00'), 0, 5);

        $date = $local->format('H:i') < $cutoff ? $local->subDay() : $local;

        return CarbonImmutable::parse($date->format('Y-m-d'), 'UTC')->startOfDay();
    }

    public function today(Outlet $outlet): CarbonImmutable
    {
        return $this->businessDate($outlet, CarbonImmutable::now());
    }

    public function isClosed(Outlet $outlet, CarbonImmutable $businessDate): bool
    {
        return BusinessDay::query()
            ->where('outlet_id', $outlet->id)
            ->whereDate('business_date', $businessDate->format('Y-m-d'))
            ->exists();
    }
}
