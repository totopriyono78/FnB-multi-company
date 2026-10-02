<?php

namespace App\Modules\Treasury\Domain\Models;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris faktur pembelian (AP-02).
 *
 * Tiap baris menunjuk akun yang akan **didebit** saat faktur diterbitkan: persediaan untuk bahan,
 * beban untuk jasa dan utilitas, uang muka untuk pembayaran di depan. Itulah satu-satunya tempat
 * di seluruh siklus pengeluaran yang memutuskan "pembelian ini masuk ke mana".
 *
 * @property string $id
 * @property string $company_id
 * @property string $purchase_invoice_id
 * @property int $line_no
 * @property string $description
 * @property string $account_id
 * @property string|null $ingredient_id
 * @property string $quantity
 * @property string $unit_price
 * @property string $amount
 */
class PurchaseInvoiceLine extends Model
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

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
