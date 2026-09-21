<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kas masuk/keluar di luar penjualan & buka laci tanpa transaksi (FR-POS-02). Append-only.
 *
 * @property string $id
 * @property string $company_id
 * @property string $shift_id
 * @property string $outlet_id
 * @property CarbonImmutable $business_date
 * @property string $type
 * @property string $amount
 * @property string $reason
 * @property string $created_by
 * @property string|null $authorized_by
 * @property CarbonImmutable $device_created_at
 * @property CarbonImmutable $server_received_at
 */
class CashMovement extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const TYPES = ['in', 'out', 'drawer_open'];

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'amount' => 'decimal:2',
            'device_created_at' => 'immutable_datetime',
            'server_received_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
