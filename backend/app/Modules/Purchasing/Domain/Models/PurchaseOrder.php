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
 * Purchase order: draft → diajukan → disetujui/ditolak → diterima (sebagian/penuh) (FR-PUR).
 *
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $outlet_id
 * @property string $location_id
 * @property string $supplier_id
 * @property string $status
 * @property CarbonImmutable $order_date
 * @property CarbonImmutable|null $expected_date
 * @property string|null $notes
 * @property string $total
 * @property string $created_by
 * @property string|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $created_at
 * @property-read Collection<int, PurchaseOrderLine> $lines
 * @property-read Supplier $supplier
 * @property-read Outlet $outlet
 * @property-read StockLocation $location
 * @property-read User|null $creator
 * @property-read Collection<int, GoodsReceipt> $receipts
 */
class PurchaseOrder extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PARTIALLY_RECEIVED = 'partially_received';

    public const RECEIVED = 'received';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Draf',
        self::SUBMITTED => 'Menunggu persetujuan',
        self::APPROVED => 'Disetujui',
        self::REJECTED => 'Ditolak',
        self::PARTIALLY_RECEIVED => 'Diterima sebagian',
        self::RECEIVED => 'Diterima lengkap',
        self::CLOSED => 'Ditutup',
        self::CANCELLED => 'Dibatalkan',
    ];

    /** Status yang masih boleh menerima barang. */
    public const RECEIVABLE = [self::APPROVED, self::PARTIALLY_RECEIVED];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'order_date' => 'immutable_date',
            'expected_date' => 'immutable_date',
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('sort_order');
    }

    /** @return HasMany<GoodsReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }
}
