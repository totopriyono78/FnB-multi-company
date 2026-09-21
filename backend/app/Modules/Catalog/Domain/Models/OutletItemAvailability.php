<?php

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $company_id
 * @property string $outlet_id
 * @property string $item_id
 * @property bool $is_listed
 * @property bool $is_sold_out
 * @property CarbonImmutable|null $sold_out_at
 * @property string|null $sold_out_by
 * @property-read Item $item
 * @property-read Outlet $outlet
 */
class OutletItemAvailability extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $table = 'outlet_item_availability';

    protected $fillable = ['outlet_id', 'item_id', 'is_listed', 'is_sold_out'];

    protected function casts(): array
    {
        return ['is_listed' => 'boolean', 'is_sold_out' => 'boolean', 'sold_out_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
