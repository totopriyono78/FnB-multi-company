<?php

namespace App\Modules\Treasury\Domain\Models;

use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Purchasing\Domain\Models\GoodsReceipt;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Faktur pembelian — tagihan masuk dari supplier (AP-02).
 *
 * Statusnya tiga saja, dan tiap perpindahan punya arti akuntansi:
 *
 * - **draft** — masih diketik; belum ada hutang, belum ada jurnal.
 * - **issued** — diterbitkan: jurnalnya ada, hutang usaha muncul, bebannya diakui.
 * - **paid** — seluruh nilainya sudah dialokasikan pembayaran.
 *
 * `cancelled` hanya untuk faktur yang belum pernah diterbitkan. Faktur yang sudah berjurnal tidak
 * dibatalkan, melainkan dikoreksi lewat jurnal balik — membatalkannya akan meninggalkan jurnal yang
 * tidak lagi punya dokumen.
 *
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $supplier_id
 * @property string|null $supplier_invoice_no
 * @property CarbonImmutable $invoice_date
 * @property CarbonImmutable|null $due_date
 * @property string|null $goods_receipt_id
 * @property string|null $outlet_id
 * @property string $subtotal
 * @property bool $has_tax_invoice
 * @property string $tax_amount
 * @property string|null $tax_invoice_no
 * @property CarbonImmutable|null $tax_invoice_date
 * @property string $total
 * @property string $paid_amount
 * @property string $status
 * @property string $description
 * @property string|null $journal_id
 * @property string $created_by
 * @property-read Collection<int, PurchaseInvoiceLine> $lines
 */
class PurchaseInvoice extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const STATUS_LABEL = [
        self::DRAFT => 'Draft',
        self::ISSUED => 'Belum lunas',
        self::PAID => 'Lunas',
        self::CANCELLED => 'Dibatalkan',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'tax_invoice_date' => 'immutable_date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'has_tax_invoice' => 'boolean',
        ];
    }

    /** @return HasMany<PurchaseInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceLine::class)->orderBy('line_no');
    }

    /** @return HasMany<PurchaseInvoicePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseInvoicePayment::class)->orderBy('paid_on');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
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

    /** Sisa hutang atas faktur ini. */
    public function outstanding(): BigDecimal
    {
        return BigDecimal::of($this->total)->minus($this->paid_amount);
    }

    /**
     * Umur hutang dalam hari sejak jatuh tempo; negatif berarti belum jatuh tempo.
     * Faktur tanpa jatuh tempo dianggap jatuh tempo pada tanggal fakturnya — tagihan tanpa tenggat
     * tetap tagihan, dan menyembunyikannya dari laporan umur hutang membuatnya terlupa.
     */
    public function daysOverdue(?CarbonImmutable $asOf = null): int
    {
        $per = $asOf ?? CarbonImmutable::now(config('app.display_timezone'))->startOfDay();
        $tempo = $this->due_date ?? $this->invoice_date;

        return (int) Carbon::parse($tempo->format('Y-m-d'))->diffInDays($per->format('Y-m-d'), false);
    }
}
