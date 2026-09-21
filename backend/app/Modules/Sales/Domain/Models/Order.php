<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transaksi penjualan (append-only, dipartisi per business_date).
 *
 * @property string $id
 * @property CarbonImmutable $business_date
 * @property string $company_id
 * @property string $outlet_id
 * @property string $device_id
 * @property string $shift_id
 * @property string $cashier_id
 * @property string $receipt_no
 * @property int|null $queue_no
 * @property string|null $sales_channel_id
 * @property string $channel_code
 * @property string|null $table_label
 * @property string|null $customer_name
 * @property string|null $note
 * @property string $status
 * @property string $subtotal
 * @property string $item_discount
 * @property string $order_discount
 * @property string $service_charge
 * @property string $tax
 * @property string $rounding
 * @property string $total
 * @property string $paid_total
 * @property string $change_amount
 * @property string $refunded_total
 * @property string $tax_name
 * @property array<string, mixed> $pricing
 * @property array<string, string> $totals
 * @property list<string>|null $promo_codes
 * @property list<string> $flags
 * @property CarbonImmutable $device_created_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $server_received_at
 * @property CarbonImmutable|null $voided_at
 * @property string|null $voided_by
 * @property string|null $void_authorized_by
 * @property string|null $void_reason
 * @property CarbonImmutable|null $void_business_date
 * @property string|null $void_stock_action
 * @property-read Outlet $outlet
 * @property-read Device $device
 * @property-read Shift $shift
 * @property-read User|null $cashier
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderPayment> $payments
 * @property-read Collection<int, OrderDiscount> $discounts
 * @property-read Collection<int, Refund> $refunds
 */
class Order extends Model
{
    use BelongsToCompany;
    use HasUuids;
    use PartitionedByBusinessDate;

    public const PAID = 'paid';

    public const VOIDED = 'voided';

    public const REFUNDED = 'refunded';

    public const PARTIALLY_REFUNDED = 'partially_refunded';

    public const STATUSES = [self::PAID, self::VOIDED, self::REFUNDED, self::PARTIALLY_REFUNDED];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'void_business_date' => 'immutable_date',
            'queue_no' => 'integer',
            'subtotal' => 'decimal:2',
            'item_discount' => 'decimal:2',
            'order_discount' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'tax' => 'decimal:2',
            'rounding' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'refunded_total' => 'decimal:2',
            'pricing' => 'array',
            'totals' => 'array',
            'promo_codes' => 'array',
            'flags' => 'array',
            'device_created_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'server_received_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class)->withTrashed();
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('line_no');
    }

    /** @return HasMany<OrderPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    /** @return HasMany<OrderDiscount, $this> */
    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isVoided(): bool
    {
        return $this->status === self::VOIDED;
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }
}
