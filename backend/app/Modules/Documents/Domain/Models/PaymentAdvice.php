<?php

namespace App\Modules\Documents\Domain\Models;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Advis bayar (DOC-05): instruksi pembayaran atas SPPK yang sudah disetujui, berikut jurnalnya.
 *
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $payment_request_id
 * @property CarbonImmutable $paid_on
 * @property string $amount
 * @property string $bank_account_id
 * @property string|null $reference
 * @property string|null $note
 * @property string|null $journal_id
 * @property string $created_by
 */
class PaymentAdvice extends Model
{
    use BelongsToCompany;
    use HasUuids;

    // "advice" tidak dijamakkan oleh Laravel menjadi "advices", jadi nama tabelnya ditulis tegas.
    protected $table = 'payment_advices';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['paid_on' => 'immutable_date', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
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
