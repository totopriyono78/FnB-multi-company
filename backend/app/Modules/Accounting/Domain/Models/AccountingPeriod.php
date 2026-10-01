<?php

namespace App\Modules\Accounting\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Periode akuntansi bulanan per entitas (ACC-04).
 *
 * Tiga keadaan (ACC-04):
 *
 * - **open** — bebas dicatat.
 * - **soft_closed** — laporan sudah terbit, tetapi koreksi yang memang milik bulan itu masih boleh
 *   masuk. Setiap posting ke periode seperti ini tercatat khusus, jadi tidak ada yang terjadi
 *   diam-diam. Ini jalan tengah yang mencegah orang membuka kembali seluruh bulan hanya demi satu
 *   koreksi kecil.
 * - **closed** — tutup permanen; posting apa pun ditolak.
 *
 * @property string $id
 * @property string $company_id
 * @property int $year
 * @property int $month
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property string $status
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by
 * @property string|null $close_note
 */
class AccountingPeriod extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const OPEN = 'open';

    public const SOFT_CLOSED = 'soft_closed';

    public const CLOSED = 'closed';

    public const STATUS_LABEL = [
        self::OPEN => 'Terbuka',
        self::SOFT_CLOSED => 'Tutup sementara',
        self::CLOSED => 'Tertutup',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isSoftClosed(): bool
    {
        return $this->status === self::SOFT_CLOSED;
    }

    public function isHardClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    /** Sudah ditutup dalam bentuk apa pun — dipakai layar untuk menawarkan "buka kembali". */
    public function isClosed(): bool
    {
        return $this->status !== self::OPEN;
    }

    public function label(): string
    {
        return CarbonImmutable::create($this->year, $this->month, 1)?->translatedFormat('F Y') ?? "{$this->month}/{$this->year}";
    }
}
