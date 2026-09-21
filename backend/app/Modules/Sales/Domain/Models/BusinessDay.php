<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hari bisnis yang sudah ditutup (FR-POS-05).
 *
 * @property string $id
 * @property string $company_id
 * @property string $outlet_id
 * @property CarbonImmutable $business_date
 * @property string $status
 * @property CarbonImmutable $closed_at
 * @property string|null $closed_by
 * @property array<string, mixed> $summary
 * @property-read Outlet $outlet
 */
class BusinessDay extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['business_date' => 'immutable_date', 'closed_at' => 'immutable_datetime', 'summary' => 'array'];
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class)->withTrashed();
    }
}
