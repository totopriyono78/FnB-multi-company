<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Rincian diskon per transaksi untuk laporan anti-fraud (FR-RPT-04). Append-only.
 *
 * @property string $id
 * @property string $company_id
 * @property string $order_id
 * @property CarbonImmutable $business_date
 * @property string|null $order_item_id
 * @property string $source
 * @property string|null $promotion_id
 * @property string $type
 * @property string $value
 * @property string $amount
 * @property string $cashier_id
 * @property string|null $authorized_by
 * @property string|null $reason
 */
class OrderDiscount extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['business_date' => 'immutable_date', 'value' => 'decimal:2', 'amount' => 'decimal:2'];
    }
}
