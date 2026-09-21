<?php

namespace App\Modules\Purchasing\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penerimaan barang, dengan atau tanpa PO (FR-INV-05). Append-only.
 *
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $outlet_id
 * @property string $location_id
 * @property string|null $supplier_id
 * @property string|null $purchase_order_id
 * @property string|null $supplier_invoice_no
 * @property string|null $notes
 * @property string $total
 * @property CarbonImmutable $business_date
 * @property CarbonImmutable $received_at
 * @property string $received_by
 * @property CarbonImmutable $created_at
 * @property-read Collection<int, GoodsReceiptLine> $lines
 * @property-read Supplier|null $supplier
 * @property-read PurchaseOrder|null $purchaseOrder
 * @property-read Outlet $outlet
 * @property-read StockLocation $location
 * @property-read User|null $receiver
 */
class GoodsReceipt extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'business_date' => 'immutable_date',
            'received_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<GoodsReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class)->withTrashed();
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
