<?php

namespace App\Modules\Treasury\Domain\Models;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu mutasi kas: masuk, keluar, atau pindah antar rekening (CSH-02, CSH-03).
 *
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $kind
 * @property CarbonImmutable $transaction_date
 * @property string $cash_account_id
 * @property string|null $contra_account_id
 * @property string|null $counter_cash_account_id
 * @property string $amount
 * @property string|null $outlet_id
 * @property string $description
 * @property string|null $reference
 * @property string|null $journal_id
 * @property string $created_by
 */
class CashTransaction extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const IN = 'in';

    public const OUT = 'out';

    public const TRANSFER = 'transfer';

    public const KIND_LABEL = [
        self::IN => 'Kas masuk',
        self::OUT => 'Kas keluar',
        self::TRANSFER => 'Transfer',
    ];

    /** Awalan nomor dokumen per jenis — supaya nomornya sendiri sudah mengatakan ia transaksi apa. */
    public const KIND_PREFIX = [
        self::IN => 'KM',
        self::OUT => 'KK',
        self::TRANSFER => 'TF',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['transaction_date' => 'immutable_date', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function counterCashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'counter_cash_account_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function contraAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'contra_account_id');
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
}
