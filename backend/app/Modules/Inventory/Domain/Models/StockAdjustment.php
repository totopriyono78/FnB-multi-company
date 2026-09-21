<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penyesuaian stok & waste (FR-INV-05). Append-only.
 *
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $outlet_id
 * @property string $location_id
 * @property string $type
 * @property string $reason_code
 * @property string|null $notes
 * @property string $total_value
 * @property CarbonImmutable $business_date
 * @property CarbonImmutable $occurred_at
 * @property string $created_by
 * @property CarbonImmutable $created_at
 * @property-read Collection<int, StockAdjustmentLine> $lines
 * @property-read Outlet $outlet
 * @property-read StockLocation $location
 * @property-read User|null $author
 */
class StockAdjustment extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const UPDATED_AT = null;

    public const TYPES = ['adjustment' => 'Penyesuaian', 'waste' => 'Waste'];

    public const WASTE_REASONS = [
        'expired' => 'Kedaluwarsa',
        'damaged' => 'Rusak / basi',
        'spilled' => 'Tumpah / jatuh',
        'prep_loss' => 'Sisa persiapan',
        'quality' => 'Tidak lolos kualitas',
        'other' => 'Lainnya',
    ];

    public const ADJUSTMENT_REASONS = [
        'opening' => 'Saldo awal',
        'correction' => 'Koreksi pencatatan',
        'found' => 'Barang ditemukan',
        'lost' => 'Barang hilang',
        'sample' => 'Sampel / promosi',
        'other' => 'Lainnya',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'total_value' => 'decimal:2',
            'business_date' => 'immutable_date',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<StockAdjustmentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class)->withTrashed();
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function reasonLabel(string $type, string $code): string
    {
        return ($type === 'waste' ? self::WASTE_REASONS : self::ADJUSTMENT_REASONS)[$code] ?? $code;
    }
}
