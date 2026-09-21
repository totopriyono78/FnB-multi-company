<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Role per company (team spatie = company). Role bawaan ditandai is_system (FR-AUTH-05).
 *
 * @property string $id
 * @property string|null $company_id
 * @property string $name
 * @property string|null $label
 * @property bool $is_system
 * @property string $max_discount_percent
 */
class Role extends SpatieRole
{
    use HasUuids;

    protected $attributes = [
        'is_system' => false,
        'max_discount_percent' => '0',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'max_discount_percent' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            $context = app(TenantContext::class);
            if ($context->isSystem()) {
                return;
            }
            $companyId = $context->companyId();
            if ($companyId === null) {
                $builder->whereRaw('1 = 0');

                return;
            }
            $builder->where(function (Builder $q) use ($companyId): void {
                $q->where('roles.company_id', $companyId)->orWhereNull('roles.company_id');
            });
        });
    }
}
