<?php

namespace App\Modules\Documents\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris matriks batas wewenang (DOC-09): "pada nilai sampai sekian, tingkat ke-N
 * ditandatangani peran ini".
 *
 * @property string $id
 * @property string $company_id
 * @property string $doc_type
 * @property string|null $max_amount null = band teratas, tanpa batas
 * @property int $level
 * @property string $role
 */
class ApprovalRule extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const PAYMENT_REQUEST = 'payment_request';

    /**
     * Jenis dokumen yang memakai matriks ini. Baru satu, dan itu disengaja: matriks ini dibuat
     * berkolom `doc_type` supaya dokumen lain (faktur pembelian, penghapusan stok) kelak memakai
     * mekanisme yang sama tanpa tabel kedua — bukan supaya layarnya menawarkan pilihan yang kosong.
     */
    public const DOC_TYPE_LABEL = [
        self::PAYMENT_REQUEST => 'Pengajuan pembayaran (SPPK)',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'max_amount' => 'decimal:2'];
    }

    public function bandLabel(): string
    {
        return $this->max_amount === null
            ? 'Di atas band lain (tanpa batas)'
            : 'Sampai Rp'.number_format((float) $this->max_amount, 0, ',', '.');
    }
}
