<?php

namespace App\Modules\Consolidation\Domain\Models;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pemetaan akun lokal entitas → akun konsolidasi (CON-02).
 *
 * Tabel ini **biasanya kosong**, dan itu memang tujuannya. Semua entitas dibuat dari
 * `AccountTemplate` yang sama, jadi aturan bawaannya adalah pemetaan menurut kode yang sama persis.
 * Pemetaan hanya dicatat untuk penyimpangan — entitas villa atau retail yang bagan akunnya datang
 * dari sistem lain, misalnya.
 *
 * `source_company_id` null berarti aturan berlaku untuk semua anggota; terisi berarti hanya untuk
 * entitas itu, dan aturan khusus mengalahkan aturan umum.
 *
 * @property string $id
 * @property string $company_id
 * @property string $group_id
 * @property string|null $source_company_id
 * @property string $source_code
 * @property string $account_id
 * @property string|null $note
 * @property-read Account|null $account
 */
class ConsolidationMapping extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $guarded = ['*'];

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
