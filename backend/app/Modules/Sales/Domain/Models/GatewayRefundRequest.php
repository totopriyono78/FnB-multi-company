<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengajuan retur untuk transaksi berbayar gateway (FR-POS-31; keputusan user 30 Sep 2026).
 *
 * Bukan retur. Selama statusnya `pending`, tidak ada satu angka pun di laporan yang berubah —
 * lihat penjelasan lengkapnya di migrasi `create_gateway_refund_requests`.
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
 * @property string $requested_by
 * @property string|null $authorized_by
 * @property string $status
 * @property string|null $gateway_reference
 * @property string|null $refund_id
 * @property string|null $resolved_by
 * @property string|null $resolution_note
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $device_created_at
 * @property CarbonImmutable $server_received_at
 */
class GatewayRefundRequest extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const PENDING = 'pending';

    public const SETTLED = 'settled';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [self::PENDING, self::SETTLED, self::CANCELLED];

    /** Tanda yang dipasang pada pesanan selama masih ada pengajuan yang menunggu. */
    public const ORDER_FLAG = 'gateway_refund_pending';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'order_business_date' => 'immutable_date',
            'business_date' => 'immutable_date',
            'amount' => 'decimal:2',
            'lines' => 'array',
            'resolved_at' => 'immutable_datetime',
            'device_created_at' => 'immutable_datetime',
            'server_received_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
