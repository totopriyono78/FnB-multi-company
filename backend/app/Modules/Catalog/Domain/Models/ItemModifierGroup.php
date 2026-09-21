<?php

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot item ↔ grup modifier. company_id diisi otomatis dari konteks tenant.
 *
 * @property string $id
 * @property string $company_id
 */
class ItemModifierGroup extends Pivot
{
    use HasUuids;

    protected $table = 'item_modifier_groups';

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (self $pivot): void {
            $pivot->company_id ??= app(TenantContext::class)->requireCompanyId();
        });
    }
}
