<?php

namespace App\Modules\Treasury\Domain\Models;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rekening kas atau bank (CSH-01).
 *
 * Satu baris = satu tempat uang yang nyata. Ia **menunjuk** satu akun buku besar dan tidak menyimpan
 * saldonya sendiri: saldo yang disimpan dan saldo yang dihitung dari buku besar pasti berbeda suatu
 * hari, dan yang salah selalu yang disimpan.
 *
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property string|null $bank_name
 * @property string|null $account_number
 * @property string|null $account_holder
 * @property string $account_id
 * @property string|null $outlet_id
 * @property bool $is_active
 * @property string|null $notes
 */
class CashAccount extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const CASH = 'cash';

    public const BANK = 'bank';

    public const KIND_LABEL = [
        self::CASH => 'Kas',
        self::BANK => 'Bank',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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

    public function label(): string
    {
        $extra = $this->kind === self::BANK && $this->account_number !== null
            ? ' · '.trim(($this->bank_name ?? '').' '.$this->account_number)
            : '';

        return $this->name.$extra;
    }
}
