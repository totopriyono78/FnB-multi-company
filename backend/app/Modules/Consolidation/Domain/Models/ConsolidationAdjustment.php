<?php

namespace App\Modules\Consolidation\Domain\Models;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Audit\Application\Auditable;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eliminasi atau penyesuaian di level konsolidasi (bagian manual dari CON-05).
 *
 * **Satu baris = satu ayat berpasangan.** Satu akun didebit, satu akun dikredit, satu nilai. Bentuk
 * ini membuat jurnal eliminasi tidak mungkin timpang — bukan karena divalidasi, tetapi karena tidak
 * ada tempat untuk menyimpan ketimpangan. Eliminasi yang membutuhkan lebih dari dua sisi ditulis
 * sebagai dua baris.
 *
 * Akun yang ditunjuk adalah akun milik entitas holding: bagan akun holding sekaligus menjadi bagan
 * akun konsolidasi, jadi tidak ada daftar akun kedua yang harus dijaga kembar.
 *
 * Eliminasi otomatis (pasangan hutang–piutang antar entitas yang dikenali sendiri) menyusul bersama
 * DOC-06 dan transfer kas antar entitas; sampai itu ada, `counterparty_company_id` di baris jurnal
 * selalu null sehingga tidak ada pasangan yang bisa dikenali.
 *
 * @property string $id
 * @property string $company_id
 * @property string $run_id
 * @property int $sequence
 * @property string $kind
 * @property string $debit_account_id
 * @property string $credit_account_id
 * @property string $amount
 * @property string $description
 * @property string|null $counterparty_note
 * @property-read Account|null $debitAccount
 * @property-read Account|null $creditAccount
 */
class ConsolidationAdjustment extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasUuids;

    public const ELIMINATION = 'elimination';

    public const ADJUSTMENT = 'adjustment';

    public const KIND_LABEL = [
        self::ELIMINATION => 'Eliminasi antar entitas',
        self::ADJUSTMENT => 'Penyesuaian konsolidasi',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['sequence' => 'integer'];
    }

    /** @return BelongsTo<ConsolidationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ConsolidationRun::class, 'run_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'debit_account_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'credit_account_id');
    }
}
