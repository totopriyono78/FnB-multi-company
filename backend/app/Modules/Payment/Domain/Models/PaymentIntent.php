<?php

namespace App\Modules\Payment\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tagihan non-tunai melalui payment gateway (QRIS dinamis / e-wallet, FR-PAY-04, FR-PAY-05).
 *
 * @property string $id
 * @property string $company_id
 * @property string $outlet_id
 * @property string $device_id
 * @property string $order_ref
 * @property string $method
 * @property string $provider
 * @property string $amount
 * @property string $status
 * @property string|null $provider_reference
 * @property string|null $qr_string
 * @property string|null $checkout_url
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $consumed_by_order_id
 * @property string $created_by
 * @property array<string, mixed>|null $provider_payload
 * @property CarbonImmutable $created_at
 * @property-read Outlet $outlet
 */
class PaymentIntent extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    /** Dibayar setelah dibatalkan/kedaluwarsa: dana masuk tetapi tidak boleh dipakai → refund manual. */
    public const PAID_LATE = 'paid_late';

    public const METHODS = ['qris', 'ewallet'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'provider_payload' => 'array',
        ];
    }

    public function isFinal(): bool
    {
        return $this->status !== self::PENDING;
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
