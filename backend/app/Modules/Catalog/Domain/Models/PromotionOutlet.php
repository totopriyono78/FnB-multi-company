<?php

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property string $id
 * @property string $company_id
 */
class PromotionOutlet extends Pivot
{
    use HasUuids;

    protected $table = 'promotion_outlets';

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (self $pivot): void {
            $pivot->company_id ??= app(TenantContext::class)->requireCompanyId();
        });
    }
}
