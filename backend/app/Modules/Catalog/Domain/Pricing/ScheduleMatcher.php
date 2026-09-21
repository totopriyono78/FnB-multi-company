<?php

namespace App\Modules\Catalog\Domain\Pricing;

use Carbon\CarbonImmutable;

/**
 * Pencocokan jadwal: daftar jendela {days:[1..7], start:"HH:MM", end:"HH:MM"}.
 * Jendela boleh melewati tengah malam (start > end); hari mengikuti hari jendela dimulai.
 * null atau daftar kosong = selalu berlaku.
 */
final class ScheduleMatcher
{
    /**
     * @param  list<array{days?: list<int>|null, start?: string|null, end?: string|null}>|null  $windows
     */
    public static function matches(?array $windows, CarbonImmutable $localTime): bool
    {
        if ($windows === null || $windows === []) {
            return true;
        }

        foreach ($windows as $window) {
            if (self::inWindow($window['days'] ?? null, $window['start'] ?? null, $window['end'] ?? null, $localTime)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>|null  $days
     */
    public static function inWindow(?array $days, ?string $start, ?string $end, CarbonImmutable $localTime): bool
    {
        $minute = $localTime->hour * 60 + $localTime->minute;
        $dow = $localTime->dayOfWeekIso;
        $from = $start === null ? 0 : self::minutes($start);
        $to = $end === null ? 24 * 60 : self::minutes($end);
        $dayOk = fn (int $d) => $days === null || $days === [] || in_array($d, $days, true);

        if ($from <= $to) {
            return $dayOk($dow) && $minute >= $from && $minute < $to;
        }

        // Melewati tengah malam: bagian malam milik hari ini, bagian dini hari milik hari sebelumnya.
        $previous = $dow === 1 ? 7 : $dow - 1;

        return ($minute >= $from && $dayOk($dow)) || ($minute < $to && $dayOk($previous));
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $h * 60 + $m;
    }
}
