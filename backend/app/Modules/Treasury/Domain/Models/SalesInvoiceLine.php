<?php

namespace App\Modules\Treasury\Domain\Models;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris tagihan keluar (AR-02). Akunnya adalah akun **pendapatan** yang akan dikredit.
 *
 * @property string $id
 * @property string $company_id
 * @property string $sales_invoice_id
 * @property int $line_no
 * @property string $description
 * @property string $account_id
 * @property string $quantity
 * @property string $unit_price
 * @property string $amount
 */
class SalesInvoiceLine extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
