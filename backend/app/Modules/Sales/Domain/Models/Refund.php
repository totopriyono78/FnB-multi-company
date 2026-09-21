<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Refund penuh/sebagian (FR-POS-31). Append-only; dicatat di hari bisnis saat refund dilakukan (BR-13).
 *
 * @property string $id
 * @property string $company_id
 * @property string $outlet_id
 * @property string $order_id
 * @property CarbonImmutable $order_business_date
 * @property CarbonImmutable $business_date
 * @property string|null $shift_id
 * @property string $amount
 * @property string $method
 * @property list<array{order_item_id: string, qty: string, amount: string}> $lines
 * @property string $stock_action
 * @property string $reason
 * @property string $refunded_by
 * @property string|null $authorized_by
 * @property list<string> $flags
 * @property CarbonImmutable $device_created_at
 * @property CarbonImmutable $server_received_at
 */
class Refund extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'order_business_date' => 'immutable_date',
            'business_date' => 'immutable_date',
            'amount' => 'decimal:2',
            'lines' => 'array',
            'flags' => 'array',
            'device_created_at' => 'immutable_datetime',
            'server_received_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
