<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Modules\Audit\Application\Auditable;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Concerns\TracksAuthor;
use App\Modules\Tenancy\Domain\DeviceStatus;
use App\Modules\Tenancy\Domain\DeviceType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

/**
 * Perangkat POS/KDS terdaftar (FR-DEV-01, FR-DEV-02, FR-DEV-07).
 *
 * @property string $id
 * @property string $company_id
 * @property string $outlet_id
 * @property string $code
 * @property string $name
 * @property DeviceType $type
 * @property string|null $platform
 * @property string|null $app_version
 * @property DeviceStatus $status
 * @property CarbonImmutable|null $paired_at
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $master_pulled_at
 * @property int|null $master_version
 * @property int $pending_sync_count
 * @property CarbonImmutable|null $wipe_requested_at
 * @property CarbonImmutable|null $revoked_at
 * @property-read Outlet $outlet
 */
class Device extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasApiTokens;
    use HasUuids;
    use TracksAuthor;

    protected $fillable = ['outlet_id', 'code', 'name', 'type'];

    protected $attributes = [
        'status' => 'pending',
        'type' => 'pos',
        'pending_sync_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => DeviceType::class,
            'status' => DeviceStatus::class,
            'paired_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'master_pulled_at' => 'immutable_datetime',
            'master_version' => 'integer',
            'wipe_requested_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'pending_sync_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->greaterThan(now()->subSeconds((int) config('fnb.devices.offline_after_seconds')));
    }

    public function isActive(): bool
    {
        return $this->status === DeviceStatus::Active;
    }
}
