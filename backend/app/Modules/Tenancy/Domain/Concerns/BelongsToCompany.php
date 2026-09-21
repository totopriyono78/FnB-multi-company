<?php

namespace App\Modules\Tenancy\Domain\Concerns;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * Lapisan isolasi tenant di aplikasi (lapisan kedua adalah RLS PostgreSQL).
 *
 * @mixin Model
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            $context = app(TenantContext::class);

            if ($context->isSystem()) {
                return;
            }

            $companyId = $context->companyId();
            if ($companyId === null) {
                // Default deny: tanpa konteks tenant tidak ada data yang terlihat.
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where($builder->getModel()->qualifyColumn('company_id'), $companyId);
        });

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            $companyId = $context->companyId();

            if ($model->getAttribute('company_id') === null) {
                if ($companyId === null) {
                    throw new LogicException('company_id wajib diisi atau konteks tenant harus aktif.');
                }
                $model->setAttribute('company_id', $companyId);
            } elseif ($companyId !== null && $model->getAttribute('company_id') !== $companyId) {
                throw new LogicException('Tidak boleh membuat data untuk company lain.');
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('company_id')) {
                throw new LogicException('company_id tidak boleh diubah.');
            }
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Route model binding yang mencatat percobaan akses data company lain (SRS §10.1).
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $field ??= $this->getRouteKeyName();

        if ($field === $this->getKeyName() && ! Str::isUuid((string) $value)) {
            throw (new ModelNotFoundException)->setModel(static::class, [$value]);
        }

        $found = $this->resolveRouteBindingQuery($this, $value, $field)->first();
        if ($found !== null) {
            return $found;
        }

        $context = app(TenantContext::class);
        if ($context->hasTenant()) {
            $foreign = $context->runAsSystem(
                fn () => static::query()->withoutGlobalScopes()->where($field, $value)->first(['id', 'company_id'])
            );

            if ($foreign !== null) {
                $metadata = [
                    'entity' => class_basename(static::class),
                    'entity_id' => (string) $value,
                    'path' => request()->path(),
                ];
                $logger = app(AuditLogger::class);
                // Log di company pelaku tanpa menyebut company pemilik data.
                $logger->log('security.cross_tenant_access', null, metadata: $metadata);
                // Log lengkap untuk tim platform (tanpa company) agar insiden bisa ditelusuri.
                $logger->log('security.cross_tenant_access', null, metadata: $metadata + [
                    'actor_company_id' => $context->companyId(),
                    'owner_company_id' => $foreign->getAttribute('company_id'),
                ], companyId: null, forcePlatform: true);
            }
        }

        throw (new ModelNotFoundException)->setModel(static::class, [$value]);
    }
}
