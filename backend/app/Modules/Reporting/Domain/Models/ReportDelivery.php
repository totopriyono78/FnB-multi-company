<?php

namespace App\Modules\Reporting\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Riwayat pengiriman laporan terjadwal (append-only).
 *
 * @property string $id
 * @property string $company_id
 * @property string $schedule_id
 * @property string $report_key
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property string $status
 * @property list<string> $recipients
 * @property string|null $filename
 * @property int|null $row_count
 * @property string|null $error
 * @property CarbonImmutable $created_at
 */
class ReportDelivery extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const UPDATED_AT = null;

    public const STATUSES = ['sent' => 'Terkirim', 'failed' => 'Gagal', 'skipped' => 'Dilewati'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }
}
