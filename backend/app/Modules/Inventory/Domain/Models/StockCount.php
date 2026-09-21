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
 * Stock opname penuh/sebagian dengan persetujuan manajer (FR-INV-06, SRS §9.4).
 *
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $outlet_id
 * @property string $location_id
 * @property string $scope
 * @property string $status
 * @property string|null $notes
 * @property CarbonImmutable $started_at
 * @property string $started_by
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $submitted_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decided_by
 * @property string|null $decision_note
 * @property string|null $variance_value
 * @property-read Collection<int, StockCountLine> $lines
 * @property-read StockLocation $location
 * @property-read Outlet $outlet
 */
class StockCount extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const COUNTING = 'counting';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::COUNTING => 'Sedang dihitung',
        self::SUBMITTED => 'Menunggu persetujuan',
        self::APPROVED => 'Disetujui',
        self::CANCELLED => 'Dibatalkan',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'variance_value' => 'decimal:2',
            'started_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<StockCountLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class)->withTrashed();
    }
}
