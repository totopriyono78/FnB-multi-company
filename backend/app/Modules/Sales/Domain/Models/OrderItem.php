<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris transaksi berisi salinan data menu saat dijual (SRS §6.3 butir 7).
 *
 * @property string $id
 * @property CarbonImmutable $business_date
 * @property string $order_id
 * @property string $company_id
 * @property int $line_no
 * @property string $item_id
 * @property string|null $item_variant_id
 * @property string $item_type
 * @property string $sku
 * @property string $name
 * @property string|null $variant_name
 * @property string|null $category_id
 * @property string|null $kitchen_station_id
 * @property string $qty
 * @property string $unit_price
 * @property string|null $catalog_price
 * @property list<array<string, mixed>> $modifiers
 * @property list<array<string, mixed>> $bundle
 * @property string $gross
 * @property string $item_discount
 * @property string $order_discount
 * @property string $net
 * @property string $status
 * @property string|null $void_reason
 * @property string|null $voided_by
 * @property string|null $void_authorized_by
 * @property CarbonImmutable|null $sent_to_kitchen_at
 * @property string|null $note
 */
class OrderItem extends Model
{
    use BelongsToCompany;
    use HasUuids;
    use PartitionedByBusinessDate;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'line_no' => 'integer',
            'qty' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'catalog_price' => 'decimal:2',
            'gross' => 'decimal:2',
            'item_discount' => 'decimal:2',
            'order_discount' => 'decimal:2',
            'net' => 'decimal:2',
            'modifiers' => 'array',
            'bundle' => 'array',
            'sent_to_kitchen_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
