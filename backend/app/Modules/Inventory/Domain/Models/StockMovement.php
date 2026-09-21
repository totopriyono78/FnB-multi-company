<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kartu stok (FR-INV-09). Append-only.
 *
 * @property string $id
 * @property string $company_id
 * @property string $outlet_id
 * @property string $location_id
 * @property string $ingredient_id
 * @property string $type
 * @property string $qty
 * @property string $unit_cost
 * @property string $value
 * @property string $balance_after
 * @property string $avg_cost_after
 * @property string $reference_type
 * @property string $reference_id
 * @property string|null $reference_no
 * @property string|null $source_key
 * @property string|null $reason
 * @property CarbonImmutable $business_date
 * @property CarbonImmutable $occurred_at
 * @property string|null $created_by
 * @property list<string> $flags
 * @property CarbonImmutable $created_at
 * @property-read Ingredient $ingredient
 * @property-read StockLocation $location
 * @property-read User|null $author
 */
class StockMovement extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const UPDATED_AT = null;

    public const TYPES = [
        'receipt' => 'Penerimaan',
        'transfer_out' => 'Transfer keluar',
        'transfer_in' => 'Transfer masuk',
        'adjustment' => 'Penyesuaian',
        'waste' => 'Waste',
        'count' => 'Selisih opname',
        'sale' => 'Pemakaian penjualan',
        'sale_return' => 'Kembali dari void/refund',
    ];

    /** Jenis mutasi yang dihitung sebagai pemakaian (food cost aktual). */
    public const CONSUMPTION_TYPES = ['sale', 'sale_return', 'waste', 'adjustment', 'count'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'value' => 'decimal:2',
            'balance_after' => 'decimal:4',
            'avg_cost_after' => 'decimal:6',
            'business_date' => 'immutable_date',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'flags' => 'array',
        ];
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withTrashed();
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
}
