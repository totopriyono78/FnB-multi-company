<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Akun pengguna global (FR-AUTH-01, FR-AUTH-04).
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string $password
 * @property bool $is_platform_admin
 * @property string $locale
 * @property int $failed_login_attempts
 * @property CarbonImmutable|null $locked_until
 * @property CarbonImmutable|null $last_login_at
 * @property string|null $last_company_id
 * @property CarbonImmutable|null $email_verified_at
 */
class User extends Authenticatable implements FilamentUser, HasTenants
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected string $guard_name = 'web';

    protected $fillable = ['name', 'email', 'phone', 'password', 'locale'];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = [
        'is_platform_admin' => false,
        'locale' => 'id',
        'failed_login_attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'failed_login_attempts' => 'integer',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** @return HasMany<CompanyUser, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    /** @return BelongsToMany<Company, $this> */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_users')
            ->withPivot(['id', 'is_active'])
            ->wherePivot('is_active', true);
    }

    /**
     * Daftar company yang dapat diakses user. Dibaca dalam mode sistem karena dipanggil
     * sebelum company aktif dipilih.
     *
     * @return Collection<int, Company>
     */
    public function accessibleCompanies(): Collection
    {
        return app(TenantContext::class)->runAsSystem(
            fn () => $this->companies()
                ->whereNull('companies.deleted_at')
                ->where('companies.status', '!=', 'suspended')
                ->orderBy('companies.name')
                ->get()
        );
    }

    public function membershipFor(string $companyId): ?CompanyUser
    {
        return app(TenantContext::class)->runAsSystem(
            fn () => CompanyUser::query()
                ->where('company_id', $companyId)
                ->where('user_id', $this->id)
                ->where('is_active', true)
                ->first()
        );
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return ! $this->trashed();
    }

    /** @return Collection<int, Company> */
    public function getTenants(Panel $panel): Collection
    {
        return $this->accessibleCompanies();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Company
            && $tenant->isAccessible()
            && $this->membershipFor($tenant->id) !== null;
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        $companies = $this->accessibleCompanies();

        return $companies->firstWhere('id', $this->last_company_id) ?? $companies->first();
    }
}
