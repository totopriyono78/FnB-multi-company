<?php

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Sales\Domain\Models\CashMovement;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Tenancy\Domain\Models\Device;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Buka/tutup shift & kas masuk/keluar (FR-POS-01..04, BR-10, BR-11). */
class ShiftService
{
    /** Toleransi jam perangkat yang lebih cepat dari server. */
    public const CLOCK_SKEW_MINUTES = 5;

    public function __construct(
        private readonly Authorizations $auth,
        private readonly BusinessCalendar $calendar,
        private readonly ShiftReport $report,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array{id: string, cashier_id: string, opening_cash: string|int|float, opened_at: string}  $data */
    public function open(Device $device, array $data): Shift
    {
        $device->loadMissing('outlet');
        $outlet = $device->outlet;
        $openedAt = $this->time($data['opened_at'], 'opened_at');
        $cashier = $this->auth->staff($data['cashier_id'], $outlet, 'pos.shift');
        $opening = $this->money($data['opening_cash'], 'opening_cash');

        $businessDate = $this->calendar->businessDate($outlet, $openedAt);

        return DB::transaction(function () use ($device, $outlet, $data, $cashier, $opening, $openedAt, $businessDate): Shift {
            // Kunci yang sama dengan tutup hari agar buka shift tidak menyusup setelah hari ditutup.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['eod:'.$outlet->id]);
            if ($this->calendar->isClosed($outlet, $businessDate)) {
                throw new SalesException('BUSINESS_DAY_CLOSED', 'Hari bisnis '.$businessDate->format('d-m-Y').' sudah ditutup.', 422);
            }
            // Kunci perangkat agar dua permintaan buka shift bersamaan tidak lolos.
            $locked = Device::query()->whereKey($device->id)->lockForUpdate()->first();
            $open = Shift::query()->where('device_id', $device->id)->where('status', Shift::OPEN)->first();
            if ($open !== null) {
                throw new SalesException('SHIFT_ALREADY_OPEN', 'Masih ada shift terbuka di perangkat ini. Tutup shift tersebut terlebih dahulu.', 409, details: ['shift_id' => $open->id]);
            }

            $shift = new Shift;
            $shift->forceFill([
                'id' => $data['id'],
                'company_id' => $device->company_id,
                'outlet_id' => $device->outlet_id,
                'device_id' => $device->id,
                'cashier_id' => $cashier->id,
                'business_date' => $businessDate->format('Y-m-d'),
                'opening_cash' => $opening,
                'opened_at' => $openedAt,
                'status' => Shift::OPEN,
                // Acuan validasi harga untuk seluruh transaksi shift ini (ADR 0004): tidak bergeser bila perangkat
                // menarik data lagi sebelum antrian shift selesai dikirim.
                'master_pulled_at' => $locked?->master_pulled_at,
                'server_received_at' => now(),
            ])->save();

            $this->audit->log('shift.opened', $shift, new: ['opening_cash' => $opening, 'business_date' => $businessDate->format('Y-m-d')], userId: $cashier->id);

            return $shift;
        });
    }

    /**
     * @param  array{id: string, shift_id: string, type: string, amount?: string|int|float|null, reason: string, created_by: string, created_at: string, authorization?: array<string, mixed>|null}  $data
     */
    public function recordCash(Device $device, array $data): CashMovement
    {
        $device->loadMissing('outlet');
        $shift = $this->openShift($device, $data['shift_id']);
        $at = $this->time($data['created_at'], 'created_at');
        $this->assertWithinShift($shift, $at);

        $type = $data['type'];
        if (! in_array($type, CashMovement::TYPES, true)) {
            throw new SalesException('INVALID_TYPE', 'Jenis pergerakan kas tidak dikenal.', 422, field: 'type');
        }

        $amount = $type === 'drawer_open' ? '0.00' : $this->money($data['amount'] ?? 0, 'amount');
        if ($type !== 'drawer_open' && ! BigDecimal::of($amount)->isPositive()) {
            throw new SalesException('INVALID_AMOUNT', 'Nominal kas harus lebih dari nol.', 422, field: 'amount');
        }

        $authorizedBy = null;
        $offlineAuth = false;
        if ($type === 'drawer_open') {
            // Buka laci tanpa transaksi: izin sendiri atau otorisasi supervisor (FR-POS-15).
            $actor = $this->auth->staff($data['created_by'], $device->outlet, 'pos.transact', 'created_by');
            if (! $this->auth->selfAuthorized($actor, 'pos.open_drawer', $device->outlet)) {
                [$supervisor, $offline] = $this->auth->verify($data['authorization'] ?? null, 'open_drawer', $device, $at, 'authorization', 'drawer:'.$data['id']);
                $authorizedBy = $supervisor->id;
                $offlineAuth = $offline;
            }
        } else {
            $actor = $this->auth->staff($data['created_by'], $device->outlet, 'pos.shift', 'created_by');
        }

        $movement = new CashMovement;
        $movement->forceFill([
            'id' => $data['id'],
            'company_id' => $device->company_id,
            'shift_id' => $shift->id,
            'outlet_id' => $shift->outlet_id,
            'business_date' => $shift->business_date->format('Y-m-d'),
            'type' => $type,
            'amount' => $amount,
            'reason' => mb_substr(trim($data['reason']), 0, 200),
            'created_by' => $actor->id,
            'authorized_by' => $authorizedBy,
            'device_created_at' => $at,
            'server_received_at' => now(),
        ])->save();

        $this->audit->log('shift.cash_'.$type, $movement, new: ['amount' => $amount, 'shift_id' => $shift->id], reason: $movement->reason, authorizedBy: $authorizedBy, metadata: $offlineAuth ? ['offline_authorization' => true] : [], userId: $actor->id);

        return $movement;
    }

    /**
     * Tutup shift dengan hitung buta: kasir hanya mengirim jumlah fisik, server menghitung selisih (FR-POS-02).
     *
     * @param  array{closed_by: string, closed_at: string, counted_cash: string|int|float, denominations?: array<string, int>|null, variance_note?: string|null}  $data
     */
    public function close(Device $device, string $shiftId, array $data): Shift
    {
        $device->loadMissing('outlet');
        $closedAt = $this->time($data['closed_at'], 'closed_at');
        $closer = $this->auth->staff($data['closed_by'], $device->outlet, 'pos.shift', 'closed_by');
        $counted = $this->money($data['counted_cash'], 'counted_cash');

        return DB::transaction(function () use ($device, $shiftId, $data, $closedAt, $closer, $counted): Shift {
            $shift = Shift::query()->whereKey($shiftId)->lockForUpdate()->first();
            $shift = $this->assertShiftOnDevice($shift, $device);
            if ($shift->status !== Shift::OPEN) {
                throw new SalesException('SHIFT_CLOSED', 'Shift sudah ditutup.', 409);
            }
            if ($closedAt->lessThan($shift->opened_at)) {
                throw new SalesException('INVALID_TIME', 'Waktu tutup shift lebih awal dari waktu buka.', 422, field: 'closed_at');
            }

            $summary = $this->report->build($shift);
            $expected = BigDecimal::of($summary['cash']['expected']);
            $variance = BigDecimal::of($counted)->minus($expected);
            $note = trim((string) ($data['variance_note'] ?? ''));
            if (! $variance->isZero() && $note === '') {
                throw new SalesException('VARIANCE_NOTE_REQUIRED', 'Ada selisih kas. Isi keterangan selisih sebelum menutup shift.', 422, field: 'variance_note', details: ['variance' => (string) $variance->toScale(2)]);
            }

            $shift->forceFill([
                'status' => Shift::CLOSED,
                'closed_at' => $closedAt,
                'closed_by' => $closer->id,
                'expected_cash' => (string) $expected->toScale(2),
                'counted_cash' => $counted,
                'cash_variance' => (string) $variance->toScale(2),
                'denominations' => $data['denominations'] ?? null,
                'variance_note' => $note === '' ? null : mb_substr($note, 0, 500),
                'summary' => $summary,
            ])->save();

            $this->audit->log('shift.closed', $shift, new: [
                'expected_cash' => $shift->expected_cash,
                'counted_cash' => $counted,
                'cash_variance' => $shift->cash_variance,
            ], reason: $shift->variance_note, userId: $closer->id);

            return $shift;
        });
    }

    public function openShift(Device $device, string $shiftId): Shift
    {
        $shift = $this->assertShiftOnDevice(Shift::query()->find($shiftId), $device);
        if ($shift->status !== Shift::OPEN) {
            throw new SalesException('SHIFT_CLOSED', 'Shift sudah ditutup; transaksi baru harus memakai shift yang terbuka.', 409, field: 'shift_id');
        }

        return $shift;
    }

    public function assertShiftOnDevice(?Shift $shift, Device $device): Shift
    {
        if ($shift === null) {
            // Bisa jadi entitas shift.open belum diterima server (antrian sinkronisasi).
            throw new SalesException('SHIFT_NOT_FOUND', 'Shift belum diterima server.', 409, true, 'shift_id');
        }
        if ($shift->device_id !== $device->id || $shift->outlet_id !== $device->outlet_id) {
            throw new SalesException('SHIFT_NOT_ON_DEVICE', 'Shift milik perangkat lain.', 422, field: 'shift_id');
        }

        return $shift;
    }

    public function assertWithinShift(Shift $shift, CarbonImmutable $at): void
    {
        if ($at->lessThan($shift->opened_at) || ($shift->closed_at !== null && $at->greaterThan($shift->closed_at))) {
            throw new SalesException('OUTSIDE_SHIFT', 'Waktu transaksi berada di luar rentang shift.', 422, field: 'created_at');
        }
    }

    public function time(mixed $value, string $field): CarbonImmutable
    {
        try {
            $at = CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            throw new SalesException('INVALID_TIME', 'Format waktu tidak valid.', 422, field: $field);
        }
        if ($at->greaterThan(now()->addMinutes(self::CLOCK_SKEW_MINUTES))) {
            throw new SalesException('CLOCK_AHEAD', 'Jam perangkat lebih cepat dari server. Sesuaikan jam perangkat.', 422, field: $field);
        }

        return $at->utc();
    }

    public function money(mixed $value, string $field): string
    {
        try {
            $amount = BigDecimal::of((string) $value);
        } catch (\Throwable) {
            throw new SalesException('INVALID_AMOUNT', 'Nominal tidak valid.', 422, field: $field);
        }
        if ($amount->isNegative() || $amount->getScale() > 2 || $amount->isGreaterThan('9999999999999999.99')) {
            throw new SalesException('INVALID_AMOUNT', 'Nominal tidak valid.', 422, field: $field);
        }

        return (string) $amount->toScale(2);
    }
}
