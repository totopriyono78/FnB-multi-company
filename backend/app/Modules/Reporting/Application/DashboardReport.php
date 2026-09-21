<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Sales\Application\BusinessCalendar;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Dashboard real-time (FR-RPT-01): penjualan hari ini per company/brand/outlet dengan perbandingan hari yang sama
 * minggu lalu sampai jam yang sama, serta kemarin (satu hari penuh).
 *
 * "Hari ini" mengikuti hari bisnis masing-masing outlet (zona waktu & jam pergantian, BR-20).
 */
class DashboardReport
{
    private const MONEY_FIELDS = ['gross_sales', 'discount', 'refund', 'refund_amount', 'net_sales', 'service_charge', 'tax', 'rounding', 'total_collected', 'void_amount'];

    private const COUNT_FIELDS = ['order_count', 'refund_count', 'void_count'];

    public function __construct(
        private readonly SalesReport $sales,
        private readonly BusinessCalendar $calendar,
    ) {}

    /** @return array<string, mixed> */
    public function build(ReportFilter $scope, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $groups = $this->groups($scope->outletIds, $now);

        $today = $this->merged($groups, $scope, 0, null);
        $lastWeek = $this->merged($groups, $scope, 7, $now->subDays(7));
        $yesterday = $this->merged($groups, $scope, 1, null);

        $dates = array_keys($groups);
        sort($dates);

        return [
            'business_date' => $dates === [] ? CarbonImmutable::now(ReportAccess::timezone())->format('Y-m-d') : end($dates),
            'generated_at' => $now->toIso8601String(),
            'outlet_count' => count($scope->outletIds),
            'today' => $today['summary'],
            'same_time_last_week' => $lastWeek['summary'],
            'yesterday' => $yesterday['summary'],
            'change' => [
                'net_sales' => self::change($today['summary']['net_sales'], $lastWeek['summary']['net_sales']),
                'order_count' => self::change((string) $today['summary']['order_count'], (string) $lastWeek['summary']['order_count']),
                'average_ticket' => self::change($today['summary']['average_ticket'], $lastWeek['summary']['average_ticket']),
            ],
            'hourly' => $this->hourly($today['hour'], $lastWeek['hour']),
            'top_items' => array_slice($today['item'], 0, 5),
            'outlets' => $today['outlet'],
            'brands' => $today['brand'],
            'payments' => $today['payment'],
        ];
    }

    /**
     * Kelompokkan outlet menurut tanggal hari bisnisnya saat ini.
     *
     * @param  list<string>  $outletIds
     * @return array<string, list<string>>
     */
    private function groups(array $outletIds, CarbonImmutable $now): array
    {
        if ($outletIds === []) {
            return [];
        }
        $groups = [];
        foreach (Outlet::withTrashed()->whereIn('id', $outletIds)->get(['id', 'timezone', 'business_day_cutoff']) as $outlet) {
            $groups[$this->calendar->businessDate($outlet, $now)->format('Y-m-d')][] = $outlet->id;
        }

        return $groups;
    }

    /**
     * @param  array<string, list<string>>  $groups
     * @return array{summary: array<string, mixed>, hour: list<array<string, mixed>>, item: list<array<string, mixed>>, outlet: list<array<string, mixed>>, brand: list<array<string, mixed>>, payment: list<array<string, mixed>>}
     */
    private function merged(array $groups, ReportFilter $scope, int $daysBack, ?CarbonImmutable $until): array
    {
        $summaries = [];
        $dims = ['hour' => [], 'item' => [], 'outlet' => [], 'brand' => [], 'payment' => []];
        $withDims = $daysBack !== 1;
        foreach ($groups as $date => $ids) {
            $day = CarbonImmutable::parse($date)->subDays($daysBack);
            $filter = $scope->withPeriod($day, $day, $ids)->until($until);
            $summaries[] = $this->sales->summary($filter);
            if (! $withDims) {
                continue;
            }
            foreach (array_keys($dims) as $dim) {
                if ($daysBack === 7 && $dim !== 'hour') {
                    continue;
                }
                $dims[$dim][] = $this->sales->breakdown($filter, $dim);
            }
        }

        $out = ['summary' => self::sumSummaries($summaries)];
        foreach ($dims as $dim => $parts) {
            $out[$dim] = self::mergeRows($parts, $dim);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $summaries
     * @return array<string, mixed>
     */
    private static function sumSummaries(array $summaries): array
    {
        $acc = [];
        foreach (self::MONEY_FIELDS as $f) {
            $acc[$f] = BigDecimal::zero();
        }
        foreach (self::COUNT_FIELDS as $f) {
            $acc[$f] = 0;
        }
        $netBefore = BigDecimal::zero();
        foreach ($summaries as $s) {
            foreach (self::MONEY_FIELDS as $f) {
                $acc[$f] = $acc[$f]->plus((string) $s[$f]);
            }
            foreach (self::COUNT_FIELDS as $f) {
                $acc[$f] += (int) $s[$f];
            }
            $netBefore = $netBefore->plus((string) $s['net_sales'])->plus((string) $s['refund']);
        }
        $out = [];
        foreach ($acc as $k => $v) {
            $out[$k] = $v instanceof BigDecimal ? SalesReport::money($v) : $v;
        }
        $out['average_ticket'] = $acc['order_count'] === 0 ? '0.00' : SalesReport::money($netBefore->dividedBy($acc['order_count'], 2, RoundingMode::HALF_UP));

        return $out;
    }

    /**
     * @param  list<list<array<string, mixed>>>  $parts
     * @return list<array<string, mixed>>
     */
    private static function mergeRows(array $parts, string $dim): array
    {
        if (count($parts) === 1) {
            return $parts[0];
        }
        $by = [];
        foreach ($parts as $rows) {
            foreach ($rows as $row) {
                $k = (string) $row['key'];
                if (! isset($by[$k])) {
                    $by[$k] = $row;

                    continue;
                }
                foreach ($row as $field => $value) {
                    if (in_array($field, ['key', 'label', 'category', 'share', 'average_ticket'], true)) {
                        continue;
                    }
                    $by[$k][$field] = is_int($value)
                        ? (int) $by[$k][$field] + $value
                        : (string) BigDecimal::of((string) $by[$k][$field])->plus((string) $value);
                }
            }
        }
        $metric = $dim === 'payment' ? 'net_received' : 'net_sales';
        $total = array_reduce($by, fn (BigDecimal $c, array $r) => $c->plus((string) $r[$metric]), BigDecimal::zero());
        foreach ($by as &$row) {
            $row['share'] = SalesReport::percent((string) $row[$metric], $total);
        }
        unset($row);
        $rows = array_values($by);
        if ($dim === 'hour') {
            usort($rows, fn ($a, $b) => (int) $a['key'] <=> (int) $b['key']);
        } else {
            usort($rows, fn ($a, $b) => BigDecimal::of((string) $b[$metric])->compareTo((string) $a[$metric]));
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $today
     * @param  list<array<string, mixed>>  $lastWeek
     * @return list<array{hour: int, label: string, net_sales: string, last_week: string, orders: int}>
     */
    private function hourly(array $today, array $lastWeek): array
    {
        $t = [];
        foreach ($today as $r) {
            $t[(int) $r['key']] = $r;
        }
        $l = [];
        foreach ($lastWeek as $r) {
            $l[(int) $r['key']] = $r;
        }
        $hours = array_merge(array_keys($t), array_keys($l));
        if ($hours === []) {
            return [];
        }
        $out = [];
        for ($h = min($hours); $h <= max($hours); $h++) {
            $out[] = [
                'hour' => $h,
                'label' => sprintf('%02d.00', $h),
                'net_sales' => (string) ($t[$h]['net_sales'] ?? '0.00'),
                'last_week' => (string) ($l[$h]['net_sales'] ?? '0.00'),
                'orders' => (int) ($t[$h]['orders'] ?? 0),
            ];
        }

        return $out;
    }

    /** Perubahan persen terhadap pembanding; null bila pembanding nol. */
    public static function change(string $current, string $previous): ?string
    {
        $prev = BigDecimal::of($previous);
        if ($prev->isZero()) {
            return null;
        }

        return (string) BigDecimal::of($current)->minus($prev)->multipliedBy(100)->dividedBy($prev->abs(), 1, RoundingMode::HALF_UP);
    }
}
