<?php

namespace App\Modules\Sync\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $device_id
 * @property int $entity_count
 * @property int $accepted_count
 * @property int $duplicate_count
 * @property int $rejected_count
 * @property int|null $clock_offset_seconds
 * @property CarbonImmutable $received_at
 */
class SyncBatch extends Model
{
    use BelongsToCompany;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime'];
    }
}
