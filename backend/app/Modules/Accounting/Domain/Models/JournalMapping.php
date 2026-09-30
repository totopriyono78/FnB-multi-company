<?php

namespace App\Modules\Accounting\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pemetaan satu slot kejadian bisnis ke satu akun (ACC-10).
 *
 * @property string $id
 * @property string $company_id
 * @property string $slot
 * @property string|null $ref_id
 * @property string $account_id
 */
class JournalMapping extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $guarded = ['*'];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
