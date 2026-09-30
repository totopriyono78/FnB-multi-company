<?php

namespace App\Modules\Accounting\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris jurnal. Mengisi debit ATAU kredit — tidak pernah keduanya, tidak pernah nol
 * (dijaga CHECK constraint, bukan hanya kode).
 *
 * @property string $id
 * @property string $company_id
 * @property string $journal_id
 * @property int $line_no
 * @property string $account_id
 * @property string $debit
 * @property string $credit
 * @property string|null $memo
 * @property string|null $brand_id
 * @property string|null $outlet_id
 * @property string|null $counterparty_company_id
 */
class JournalLine extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'created_at';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
