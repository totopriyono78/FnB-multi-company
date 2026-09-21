<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Audit\Application\Auditable;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bahan baku / bahan setengah jadi (FR-INV-01, FR-INV-03).
 *
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string|null $category
 * @property string $base_unit
 * @property string $kind
 * @property string $min_stock
 * @property string|null $last_cost
 * @property bool $is_active
 * @property string|null $notes
 * @property-read Collection<int, IngredientUnit> $units
 * @property-read Recipe|null $recipe
 */
class Ingredient extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasUuids;
    use SoftDeletes;
    use TracksAuthor;

    public const RAW = 'raw';

    public const SEMI = 'semi';

    public const KINDS = [self::RAW => 'Bahan baku', self::SEMI => 'Setengah jadi'];

    /** Satuan dasar yang didukung (resep & stok selalu dalam satuan ini). */
    public const BASE_UNITS = ['g' => 'gram', 'ml' => 'mililiter', 'pcs' => 'pcs', 'lembar' => 'lembar', 'porsi' => 'porsi'];

    protected $fillable = ['code', 'name', 'category', 'base_unit', 'kind', 'min_stock', 'is_active', 'notes'];

    protected $attributes = [
        'kind' => self::RAW,
        'min_stock' => '0',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'min_stock' => 'decimal:4',
            'last_cost' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<IngredientUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(IngredientUnit::class)->orderBy('factor');
    }

    /** @return HasOne<Recipe, $this> */
    public function recipe(): HasOne
    {
        return $this->hasOne(Recipe::class, 'target_id')->where('target_type', Recipe::INGREDIENT);
    }

    public function isSemi(): bool
    {
        return $this->kind === self::SEMI;
    }
}
