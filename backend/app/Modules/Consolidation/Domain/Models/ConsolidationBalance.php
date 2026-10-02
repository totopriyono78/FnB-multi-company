<?php

namespace App\Modules\Consolidation\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo satu akun satu entitas di dalam satu proses konsolidasi (CON-01).
 *
 * `opening`, `period`, dan `closing` **bertanda menurut kelompok akunnya**, aturan yang sama dengan
 * `FinancialStatements`. Karena itu menjumlahkan antar entitas cukup penjumlahan biasa, dan akun
 * lawan tetap mengurangi kelompoknya.
 *
 * @property string $id
 * @property string $company_id
 * @property string $run_id
 * @property string $source_company_id
 * @property string $account_code
 * @property string $account_name
 * @property string $account_type
 * @property string $target_code
 * @property string $target_name
 * @property string $target_type
 * @property string $opening
 * @property string $period
 * @property string $closing
 */
class ConsolidationBalance extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $guarded = ['*'];

    /** @return BelongsTo<ConsolidationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ConsolidationRun::class, 'run_id');
    }
}
