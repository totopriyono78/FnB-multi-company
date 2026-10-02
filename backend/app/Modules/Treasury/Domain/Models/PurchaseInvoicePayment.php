<?php

namespace App\Modules\Treasury\Domain\Models;

use App\Modules\Documents\Domain\Models\PaymentAdvice;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alokasi satu pembayaran ke satu faktur (AP-03).
 *
 * Disimpan sebagai baris tersendiri, bukan kolom di faktur: satu advis bayar sering melunasi
 * beberapa faktur sekaligus — itu justru cara paling lazim membayar supplier — dan satu faktur bisa
 * dilunasi beberapa kali. Hanya tabel alokasi yang bisa menjawab kedua arah pertanyaan itu.
 *
 * @property string $id
 * @property string $company_id
 * @property string $purchase_invoice_id
 * @property string|null $payment_advice_id
 * @property string $amount
 * @property CarbonImmutable $paid_on
 */
class PurchaseInvoicePayment extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'immutable_date'];
    }

    /** @return BelongsTo<PurchaseInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    /** @return BelongsTo<PaymentAdvice, $this> */
    public function advice(): BelongsTo
    {
        return $this->belongsTo(PaymentAdvice::class, 'payment_advice_id');
    }
}
