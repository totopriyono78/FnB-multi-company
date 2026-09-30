<?php

namespace App\Modules\Accounting\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Periode akuntansi bulanan per entitas (ACC-04).
 *
 * Periode tertutup menolak posting apa pun — itu satu-satunya arti "tutup buku" yang bisa dipercaya.
 * Tutup lunak (soft close, indikatif) belum dibuat; catat di rencana bila nanti dibutuhkan.
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

    public const CLOSED = 'closed';

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

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    public function label(): string
    {
        return CarbonImmutable::create($this->year, $this->month, 1)?->translatedFormat('F Y') ?? "{$this->month}/{$this->year}";
    }
}
