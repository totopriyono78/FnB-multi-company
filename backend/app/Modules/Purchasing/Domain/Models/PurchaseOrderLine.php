<?php

namespace App\Modules\Purchasing\Domain\Models;

use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $company_id
 * @property string $purchase_order_id
 * @property string $ingredient_id
 * @property string $unit_name
 * @property string $unit_factor
 * @property string $qty
 * @property string $unit_price
 * @property string $line_total
 * @property string $received_qty
 * @property int $sort_order
 * @property-read Ingredient $ingredient
 */
class PurchaseOrderLine extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'unit_factor' => 'decimal:4',
            'qty' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'received_qty' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withTrashed();
    }
}
