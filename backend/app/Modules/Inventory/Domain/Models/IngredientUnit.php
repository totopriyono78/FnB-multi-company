<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satuan beli & konversi ke satuan dasar (mis. 1 karton = 12.000 ml).
 *
 * @property string $id
 * @property string $company_id
 * @property string $ingredient_id
 * @property string $name
 * @property string $factor
 * @property bool $is_purchase_default
 * @property-read Ingredient $ingredient
 */
class IngredientUnit extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $fillable = ['ingredient_id', 'name', 'factor', 'is_purchase_default'];

    protected function casts(): array
    {
        return ['factor' => 'decimal:4', 'is_purchase_default' => 'boolean'];
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
