<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $company_id
 * @property string $stock_adjustment_id
 * @property string $ingredient_id
 * @property string $qty
 * @property string $unit_cost
 * @property string $value
 * @property string|null $note
 * @property-read Ingredient $ingredient
 */
class StockAdjustmentLine extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'unit_cost' => 'decimal:6', 'value' => 'decimal:2'];
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withTrashed();
    }
}
