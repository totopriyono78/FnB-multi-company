<?php

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Riwayat harga append-only (FR-MENU-15).
 *
 * @property string $id
 * @property string $company_id
 * @property string $item_id
 * @property string|null $item_variant_id
 * @property string|null $outlet_id
 * @property string|null $sales_channel_id
 * @property string|null $old_price
 * @property string|null $new_price
 * @property string|null $changed_by
 * @property CarbonImmutable $created_at
 */
class ItemPriceHistory extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'old_price' => 'decimal:2',
            'new_price' => 'decimal:2',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Riwayat harga tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Riwayat harga tidak dapat dihapus.'));
    }

    /** @return BelongsTo<ItemVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class, 'item_variant_id');
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class)->withTrashed();
    }

    /** @return BelongsTo<SalesChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'sales_channel_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
