<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo stok per lokasi per bahan. Hanya diubah oleh StockLedger.
 *
 * @property string $id
 * @property string $company_id
 * @property string $location_id
 * @property string $ingredient_id
 * @property string $qty
 * @property string $avg_cost
 * @property string|null $min_qty
 * @property CarbonImmutable|null $last_movement_at
 * @property-read Ingredient $ingredient
 * @property-read StockLocation $location
 */
class StockBalance extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'avg_cost' => 'decimal:6',
            'min_qty' => 'decimal:4',
            'last_movement_at' => 'immutable_datetime',
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
}
