<?php

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Concerns\TracksAuthor;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $company_id
 * @property string $item_id
 * @property string|null $item_variant_id
 * @property string|null $outlet_id
 * @property string|null $sales_channel_id
 * @property string $price
 * @property-read Item $item
 * @property-read ItemVariant|null $variant
 * @property-read Outlet|null $outlet
 * @property-read SalesChannel|null $channel
 */
class ItemPrice extends Model
{
    use BelongsToCompany;
    use HasUuids;
    use TracksAuthor;

    protected $table = 'item_prices';

    protected $fillable = ['item_id', 'item_variant_id', 'outlet_id', 'sales_channel_id', 'price'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<ItemVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class, 'item_variant_id');
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<SalesChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'sales_channel_id');
    }
}
