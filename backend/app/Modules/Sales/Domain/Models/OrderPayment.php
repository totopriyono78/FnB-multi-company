<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pembayaran order (split payment didukung). Tabel `payments`, append-only.
 *
 * @property string $id
 * @property CarbonImmutable $business_date
 * @property string $order_id
 * @property string $company_id
 * @property string $outlet_id
 * @property string $shift_id
 * @property string $method
 * @property string $amount
 * @property string|null $tendered
 * @property string $change_amount
 * @property string|null $reference
 * @property string|null $payment_intent_id
 * @property string $mdr_amount
 * @property CarbonImmutable $device_created_at
 */
class OrderPayment extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'payments';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'amount' => 'decimal:2',
            'tendered' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'mdr_amount' => 'decimal:2',
            'device_created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
