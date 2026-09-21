<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Pesanan dikirim ke dapur dari POS (FR-POS-20). Append-only; dasar potong stok "saat kirim dapur".
 *
 * @property string $id
 * @property string $company_id
 * @property string $outlet_id
 * @property string $device_id
 * @property string|null $shift_id
 * @property string $order_id
 * @property string $sent_by
 * @property CarbonImmutable $business_date
 * @property CarbonImmutable $sent_at
 * @property list<array{id: string, item_id: string, variant_id: string|null, qty: string, modifiers: list<array{id: string, qty: int}>, bundle: list<array{option_id: string, item_id: string|null, variant_id: string|null}>, kitchen_station_id: string|null}> $lines
 * @property CarbonImmutable $server_received_at
 */
class KitchenTicket extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'sent_at' => 'immutable_datetime',
            'server_received_at' => 'immutable_datetime',
            'lines' => 'array',
        ];
    }
}
