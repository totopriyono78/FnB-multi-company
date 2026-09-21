<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\CompanyStatus;
use App\Modules\Tenancy\Domain\Events\CompanyRegistered;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Domain\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Pendaftaran company baru beserta pemiliknya (FR-TEN-01, FR-TEN-02).
 */
class CompanyRegistrar
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PermissionRegistrar $permissions,
    ) {}

    /**
     * @param  array{name: string, email?: string|null, phone?: string|null, city?: string|null, timezone?: string|null}  $company
     */
    public function register(array $company, User $owner, ?string $planCode = null): Company
    {
        return DB::transaction(function () use ($company, $owner, $planCode): Company {
            $created = $this->context->runAsSystem(function () use ($company, $planCode): Company {
                $plan = Plan::query()->where('code', $planCode ?? 'basic')->where('is_active', true)->first();

                $model = new Company;
                $model->fill([
                    'name' => $company['name'],
                    'email' => $company['email'] ?? null,
                    'phone' => $company['phone'] ?? null,
                    'city' => $company['city'] ?? null,
                    'timezone' => $company['timezone'] ?? 'Asia/Jakarta',
                ]);
                $model->forceFill([
                    'code' => $this->uniqueCode($company['name']),
                    'status' => CompanyStatus::Trial,
                    'plan_id' => $plan?->id,
                    'subscription_ends_at' => now()->addDays((int) config('fnb.subscription.trial_days')),
                ])->save();

                return $model;
            });

            $this->context->runAsTenant($created->id, function () use ($created, $owner): void {
                $membership = new CompanyUser(['user_id' => $owner->id]);
                $membership->save();

                event(new CompanyRegistered($created->id, $owner->id));

                $this->permissions->setPermissionsTeamId($created->id);
                $owner->unsetRelation('roles');
                $owner->assignRole('owner');
            });

            return $created;
        });
    }

    private function uniqueCode(string $name): string
    {
        $base = Str::limit(Str::slug($name), 30, '') ?: 'company';
        $code = $base;

        while (Company::withTrashed()->where('code', $code)->exists()) {
            $code = $base.'-'.Str::lower(Str::random(4));
        }

        return $code;
    }
}
