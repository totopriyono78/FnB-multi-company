<?php

namespace App\Modules\Reporting\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jadwal pengiriman laporan via email (FR-RPT-08). Laporan dibangun dengan hak akses pembuat jadwal.
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property string $report_key
 * @property string $format
 * @property string $frequency
 * @property string $send_time
 * @property string|null $brand_id
 * @property string|null $outlet_id
 * @property list<string> $recipients
 * @property bool $is_active
 * @property string $created_by
 * @property string|null $updated_by
 * @property CarbonImmutable|null $next_run_at
 * @property CarbonImmutable|null $last_run_at
 * @property string|null $last_status
 * @property string|null $disabled_reason
 * @property CarbonImmutable $created_at
 * @property-read User|null $owner
 */
class ReportSchedule extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const FREQUENCIES = ['daily' => 'Harian (kemarin)', 'weekly' => 'Mingguan (Senin–Minggu lalu)', 'monthly' => 'Bulanan (bulan lalu)'];

    public const MAX_RECIPIENTS = 10;

    protected $guarded = ['*'];

    protected $attributes = ['is_active' => true, 'send_time' => '07:00', 'recipients' => '[]'];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'is_active' => 'boolean',
            'next_run_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** @return HasMany<ReportDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ReportDelivery::class, 'schedule_id');
    }
}
