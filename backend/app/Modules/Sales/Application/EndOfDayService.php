<?php

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Catalog\Domain\Models\OutletItemAvailability;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Events\BusinessDayClosed;
use App\Modules\Sales\Domain\Models\BusinessDay;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Sync\Application\SyncVersions;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Tutup hari (FR-POS-05, BR-20). */
class EndOfDayService
{
    public function __construct(
        private readonly Authorizations $auth,
        private readonly BusinessCalendar $calendar,
        private readonly SyncVersions $versions,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> Ringkasan pratinjau (tanpa menutup). */
    public function preview(Outlet $outlet, CarbonImmutable $date): array
    {
        $openShifts = Shift::query()
            ->where('outlet_id', $outlet->id)
            ->where('business_date', $date->format('Y-m-d'))
            ->where('status', Shift::OPEN)
            ->with('cashier:id,name')
            ->get()
            ->map(fn (Shift $s) => ['id' => $s->id, 'device_id' => $s->device_id, 'cashier' => $s->cashier?->name, 'opened_at' => $s->opened_at->toIso8601String()])
            ->values()
            ->all();

        return [
            'outlet_id' => $outlet->id,
            'business_date' => $date->format('Y-m-d'),
            'closed' => $this->calendar->isClosed($outlet, $date),
            'open_shifts' => $openShifts,
            'summary' => $this->summary($outlet, $date),
        ];
    }

    public function close(Outlet $outlet, CarbonImmutable $date, User $user, ?string $note = null): BusinessDay
    {
        if (! $this->auth->userCan($user, 'pos.end_of_day', $outlet)) {
            throw new SalesException('FORBIDDEN', 'Anda tidak berwenang menutup hari di outlet ini.', 403);
        }
        if ($date->greaterThan($this->calendar->today($outlet))) {
            throw new SalesException('BUSINESS_DAY_IN_FUTURE', 'Hari bisnis belum dimulai.', 422, field: 'business_date');
        }

        return DB::transaction(function () use ($outlet, $date, $user, $note): BusinessDay {
            // Serialisasi per outlet: buka shift & tutup hari tidak boleh bersilangan.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['eod:'.$outlet->id]);
            Outlet::query()->whereKey($outlet->id)->lockForUpdate()->first();

            if ($this->calendar->isClosed($outlet, $date)) {
                throw new SalesException('BUSINESS_DAY_CLOSED', 'Hari bisnis ini sudah ditutup.', 409, field: 'business_date');
            }
            $open = Shift::query()->where('outlet_id', $outlet->id)->where('business_date', $date->format('Y-m-d'))->where('status', Shift::OPEN)->pluck('id');
            if ($open->isNotEmpty()) {
                throw new SalesException('SHIFTS_OPEN', 'Masih ada shift yang belum ditutup.', 409, details: ['shift_ids' => $open->all()]);
            }

            $summary = $this->summary($outlet, $date);
            if ($note !== null && trim($note) !== '') {
                $summary['note'] = mb_substr(trim($note), 0, 500);
            }

            // Status "habis" kembali tersedia untuk hari berikutnya (FR-POS-05).
            $reset = OutletItemAvailability::query()
                ->where('outlet_id', $outlet->id)
                ->where('is_sold_out', true)
                ->update(['is_sold_out' => false, 'sold_out_at' => null, 'sold_out_by' => null, 'updated_at' => now()]);
            $summary['sold_out_reset'] = $reset;

            $day = new BusinessDay;
            $day->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $outlet->company_id,
                'outlet_id' => $outlet->id,
                'business_date' => $date->format('Y-m-d'),
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $user->id,
                'summary' => $summary,
            ])->save();

            $this->versions->bump($outlet->company_id);

            $this->audit->log('business_day.closed', $day, new: [
                'business_date' => $date->format('Y-m-d'),
                'net_sales' => $summary['net_sales'],
                'sold_out_reset' => $reset,
            ], reason: $summary['note'] ?? null, userId: $user->id);

            DB::afterCommit(fn () => BusinessDayClosed::dispatch($outlet->company_id, $outlet->id, $date->format('Y-m-d')));

            return $day;
        });
    }

    /** @return array<string, mixed> */
    public function summary(Outlet $outlet, CarbonImmutable $date): array
    {
        $d = $date->format('Y-m-d');
        $orders = DB::table('orders')
            ->where('company_id', $outlet->company_id)
            ->where('outlet_id', $outlet->id)
            ->where('business_date', $d)
            ->selectRaw("
                count(*) filter (where status <> 'voided') as order_count,
                count(*) filter (where status = 'voided') as void_count,
                coalesce(sum(total) filter (where status <> 'voided'), 0) as sales_total,
                coalesce(sum(subtotal) filter (where status <> 'voided'), 0) as gross_total,
                coalesce(sum(item_discount + order_discount) filter (where status <> 'voided'), 0) as discount_total,
                coalesce(sum(service_charge) filter (where status <> 'voided'), 0) as service_charge_total,
                coalesce(sum(tax) filter (where status <> 'voided'), 0) as tax_total,
                coalesce(sum(rounding) filter (where status <> 'voided'), 0) as rounding_total,
                count(*) filter (where flags <> '[]'::jsonb) as flagged_count
            ")
            ->first();

        $payments = DB::table('payments as p')
            ->join('orders as o', function ($join): void {
                $join->on('o.id', '=', 'p.order_id')->on('o.business_date', '=', 'p.business_date');
            })
            ->where('p.company_id', $outlet->company_id)
            ->where('p.outlet_id', $outlet->id)
            ->where('p.business_date', $d)
            ->where('o.status', '<>', 'voided')
            ->groupBy('p.method')
            ->selectRaw('p.method, count(*) as count, sum(p.amount) as amount, sum(p.mdr_amount) as mdr')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->method => ['count' => (int) $r->count, 'amount' => $this->money($r->amount), 'mdr' => $this->money($r->mdr)]])
            ->all();

        $refunds = DB::table('refunds')
            ->where('company_id', $outlet->company_id)
            ->where('outlet_id', $outlet->id)
            ->where('business_date', $d)
            ->selectRaw('count(*) as count, coalesce(sum(amount), 0) as amount')
            ->first();

        $shifts = DB::table('shifts')
            ->where('company_id', $outlet->company_id)
            ->where('outlet_id', $outlet->id)
            ->where('business_date', $d)
            ->selectRaw("count(*) as count, coalesce(sum(cash_variance) filter (where status = 'closed'), 0) as variance")
            ->first();

        $sales = BigDecimal::of((string) ($orders->sales_total ?? '0'));
        $refundTotal = BigDecimal::of((string) ($refunds->amount ?? '0'));

        return [
            'order_count' => (int) ($orders->order_count ?? 0),
            'void_count' => (int) ($orders->void_count ?? 0),
            'flagged_count' => (int) ($orders->flagged_count ?? 0),
            'gross_total' => $this->money($orders->gross_total ?? 0),
            'discount_total' => $this->money($orders->discount_total ?? 0),
            'service_charge_total' => $this->money($orders->service_charge_total ?? 0),
            'tax_total' => $this->money($orders->tax_total ?? 0),
            'rounding_total' => $this->money($orders->rounding_total ?? 0),
            'sales_total' => (string) $sales->toScale(2),
            'refund_count' => (int) ($refunds->count ?? 0),
            'refund_total' => (string) $refundTotal->toScale(2),
            'net_sales' => (string) $sales->minus($refundTotal)->toScale(2),
            'payments' => $payments,
            'shift_count' => (int) ($shifts->count ?? 0),
            'cash_variance_total' => $this->money($shifts->variance ?? 0),
        ];
    }

    private function money(mixed $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(2);
    }
}
