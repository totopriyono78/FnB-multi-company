<?php

namespace App\Modules\Documents\Domain\Models;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SPPK — surat permintaan pembayaran (DOC-04).
 *
 * Satu pengajuan pembayaran ke pihak ketiga: siapa pemohonnya, siapa penerimanya, berapa, dibebankan
 * ke akun apa, dan buktinya apa. Setelah disetujui sesuai batas wewenang, ia menjadi dasar advis bayar.
 *
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property CarbonImmutable $request_date
 * @property CarbonImmutable|null $due_date
 * @property string|null $outlet_id
 * @property string|null $supplier_id
 * @property string $payee_name
 * @property string $amount
 * @property string $paid_amount
 * @property string $expense_account_id
 * @property string $description
 * @property string $status
 * @property int $required_levels
 * @property string $requested_by
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $approved_at
 * @property string|null $rejected_by
 * @property CarbonImmutable|null $rejected_at
 * @property string|null $reject_reason
 */
class PaymentRequest extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const STATUS_LABEL = [
        self::DRAFT => 'Draft',
        self::SUBMITTED => 'Menunggu persetujuan',
        self::APPROVED => 'Disetujui',
        self::REJECTED => 'Ditolak',
        self::PAID => 'Dibayar',
        self::CANCELLED => 'Dibatalkan',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'request_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'required_levels' => 'integer',
        ];
    }

    /** @return HasMany<PaymentRequestApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(PaymentRequestApproval::class)->orderBy('level');
    }

    /** @return HasMany<PaymentAdvice, $this> */
    public function advices(): HasMany
    {
        return $this->hasMany(PaymentAdvice::class)->orderBy('paid_on');
    }

    /** @return BelongsTo<Account, $this> */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    /** Tingkat berikutnya yang menunggu tanda tangan; null bila sudah lengkap. */
    public function nextLevel(): ?int
    {
        if ($this->status !== self::SUBMITTED) {
            return null;
        }
        $sudah = (int) $this->approvals()->max('level');

        return $sudah >= $this->required_levels ? null : $sudah + 1;
    }

    /** Sisa yang belum dibayar. */
    public function outstanding(): BigDecimal
    {
        return BigDecimal::of($this->amount)->minus($this->paid_amount);
    }
}
