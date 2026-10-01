<?php

namespace App\Modules\Accounting\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu kali dana settlement masuk rekening (ACC-12).
 *
 * @property string $id
 * @property string $company_id
 * @property string|null $outlet_id
 * @property string $method
 * @property CarbonImmutable $settled_on
 * @property string $gross_amount
 * @property string $fee_amount
 * @property string $net_amount
 * @property string $bank_account_id
 * @property string|null $fee_account_id
 * @property string|null $reference
 * @property string|null $note
 * @property string|null $journal_id
 * @property string $created_by
 */
class SettlementBatch extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'settled_on' => 'immutable_date',
            'gross_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
