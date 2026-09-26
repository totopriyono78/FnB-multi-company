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
 * Tagihan terbuka / parkir bill (FR-POS-12).
 *
 * Tamu memesan lalu makan dulu dan membayar belakangan. Selama belum dibayar, isinya masih
 * bisa berubah, jadi tidak boleh disimpan di `orders` yang bersifat append-only. Saat dibayar,
 * tagihan ini ditutup (`closed_at`) dan ditautkan ke transaksi resmi lewat `order_id`.
 *
 * Milik outlet, bukan milik perangkat: kasir mana pun di outlet yang sama boleh membukanya.
 *
 * @property string $id
 * @property string $company_id
 * @property string $outlet_id
 * @property string|null $device_id
 * @property string|null $order_id
 * @property string|null $label
 * @property string|null $table_label
 * @property string|null $customer_name
 * @property string|null $note
 * @property int|null $queue_no
 * @property string $channel_code
 * @property list<array<string, mixed>> $lines
 * @property array<string, mixed>|null $totals
 * @property CarbonImmutable $business_date
 * @property string $opened_by
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 */
class OpenBill extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'totals' => 'array',
            'queue_no' => 'integer',
            'business_date' => 'immutable_date',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
