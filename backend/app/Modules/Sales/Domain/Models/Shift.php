<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shift kasir (FR-POS-01..04). ID dibuat perangkat.
 *
 * @property string $id
 * @property string $company_id
 * @property string $outlet_id
 * @property string $device_id
 * @property string $cashier_id
 * @property CarbonImmutable $business_date
 * @property string $opening_cash
 * @property CarbonImmutable $opened_at
 * @property string $status
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by
 * @property string|null $expected_cash
 * @property string|null $counted_cash
 * @property string|null $cash_variance
 * @property array<string, int>|null $denominations
 * @property string|null $variance_note
 * @property array<string, mixed>|null $summary
 * @property CarbonImmutable|null $master_pulled_at
 * @property CarbonImmutable $server_received_at
 * @property-read Outlet $outlet
 * @property-read Device $device
 * @property-read User|null $cashier
 */
class Shift extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'server_received_at' => 'immutable_datetime',
            'master_pulled_at' => 'immutable_datetime',
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'cash_variance' => 'decimal:2',
            'denominations' => 'array',
            'summary' => 'array',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class)->withTrashed();
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /** @return HasMany<CashMovement, $this> */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
