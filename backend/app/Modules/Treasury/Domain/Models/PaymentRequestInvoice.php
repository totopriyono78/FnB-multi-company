<?php

namespace App\Modules\Treasury\Domain\Models;

use App\Modules\Documents\Domain\Models\PaymentRequest;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faktur yang hendak dilunasi oleh sebuah SPPK (AP-03).
 *
 * Inilah sambungan antara Kelompok 4 dan Kelompok 5: SPPK tetap alat persetujuannya, faktur tetap
 * alat pencatat hutangnya, dan baris ini yang mengatakan pengajuan mana membayar tagihan mana.
 *
 * @property string $id
 * @property string $company_id
 * @property string $payment_request_id
 * @property string $purchase_invoice_id
 * @property string $amount
 */
class PaymentRequestInvoice extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    /** @return BelongsTo<PurchaseInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }
}
