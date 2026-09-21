<?php

namespace App\Modules\Sync\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Bukti penerimaan entitas sinkronisasi untuk idempotensi (NFR-OFF-04).
 *
 * @property string $id
 * @property string $company_id
 * @property string $device_id
 * @property string|null $batch_id
 * @property string $entity_type
 * @property string $entity_id
 * @property string $payload_hash
 * @property array<string, mixed> $result
 */
class SyncReceipt extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
