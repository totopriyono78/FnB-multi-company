<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Concerns\TracksAuthor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Resep (BOM) untuk menu, varian, modifier, atau bahan setengah jadi (FR-INV-03).
 *
 * @property string $id
 * @property string $company_id
 * @property string $target_type
 * @property string $target_id
 * @property string|null $brand_id
 * @property string $yield_qty
 * @property string|null $notes
 * @property string|null $updated_by
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, RecipeLine> $lines
 */
class Recipe extends Model
{
    use BelongsToCompany;
    use HasUuids;
    use TracksAuthor;

    public const ITEM = 'item';

    public const VARIANT = 'variant';

    public const MODIFIER = 'modifier';

    public const INGREDIENT = 'ingredient';

    public const TARGETS = [self::ITEM, self::VARIANT, self::MODIFIER, self::INGREDIENT];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['yield_qty' => 'decimal:4', 'updated_at' => 'immutable_datetime'];
    }

    /** @return HasMany<RecipeLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(RecipeLine::class)->orderBy('sort_order');
    }
}
