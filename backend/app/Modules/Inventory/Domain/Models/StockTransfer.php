<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transfer antar gudang/outlet dengan status kirim–terima (FR-INV-05).
 *
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $from_outlet_id
 * @property string $from_location_id
 * @property string $to_outlet_id
 * @property string $to_location_id
 * @property string $status
 * @property string|null $notes
 * @property string $total_value
 * @property string $sent_by
 * @property CarbonImmutable $sent_at
 * @property string|null $received_by
 * @property CarbonImmutable|null $received_at
 * @property string|null $receive_note
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property-read Collection<int, StockTransferLine> $lines
 * @property-read StockLocation $fromLocation
 * @property-read StockLocation $toLocation
 * @property-read Outlet $fromOutlet
 * @property-read Outlet $toOutlet
 */
class StockTransfer extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const IN_TRANSIT = 'in_transit';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::IN_TRANSIT => 'Dalam pengiriman', self::RECEIVED => 'Diterima', self::CANCELLED => 'Dibatalkan'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'total_value' => 'decimal:2',
            'sent_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<StockTransferLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'from_location_id');
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'to_location_id');
    }

    /** @return BelongsTo<Outlet, $this> */
    public function fromOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'from_outlet_id')->withTrashed();
    }

    /** @return BelongsTo<Outlet, $this> */
    public function toOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'to_outlet_id')->withTrashed();
    }
}
