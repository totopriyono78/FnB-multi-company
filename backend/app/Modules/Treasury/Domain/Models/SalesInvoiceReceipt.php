<?php

namespace App\Modules\Treasury\Domain\Models;

use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penerimaan pelunasan piutang (AR-02).
 *
 * Menunjuk **rekening kas/bank yang nyata**, bukan akun buku besar: yang menerima uang adalah
 * rekening tertentu, dan rekonsiliasi bank nanti mencari pasangannya di rekening itu.
 *
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $sales_invoice_id
 * @property string $cash_account_id
 * @property CarbonImmutable $received_on
 * @property string $amount
 * @property string|null $reference
 * @property string|null $journal_id
 * @property string $created_by
 */
class SalesInvoiceReceipt extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['received_on' => 'immutable_date', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<SalesInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
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
}
