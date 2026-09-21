<?php

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Audit\Application\Auditable;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property int $sort_order
 * @property bool $is_active
 */
class KitchenStation extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasUuids;

    protected $table = 'kitchen_stations';

    protected $fillable = ['code', 'name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return HasMany<Item, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'kitchen_station_id');
    }
}
