<?php

namespace App\Modules\Treasury\Domain\Models;

use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Identity\Domain\Models\User;
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
 * Tagihan keluar — penjualan yang uangnya datang belakangan (AR-02).
 *
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $customer_id
 * @property CarbonImmutable $invoice_date
 * @property CarbonImmutable|null $due_date
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
 * @property-read Collection<int, SalesInvoiceLine> $lines
 */
class SalesInvoice extends Model
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

    /** @return HasMany<SalesInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class)->orderBy('line_no');
    }

    /** @return HasMany<SalesInvoiceReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(SalesInvoiceReceipt::class)->orderBy('received_on');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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

    public function outstanding(): BigDecimal
    {
        return BigDecimal::of($this->total)->minus($this->paid_amount);
    }

    /** Umur piutang dalam hari sejak jatuh tempo; negatif berarti belum jatuh tempo. */
    public function daysOverdue(?CarbonImmutable $asOf = null): int
    {
        $per = $asOf ?? CarbonImmutable::now(config('app.display_timezone'))->startOfDay();
        $tempo = $this->due_date ?? $this->invoice_date;

        return (int) Carbon::parse($tempo->format('Y-m-d'))->diffInDays($per->format('Y-m-d'), false);
    }
}
