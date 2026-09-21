<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Modules\Audit\Application\Auditable;
use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\KitchenStation;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use App\Modules\Catalog\Domain\Models\Promotion;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Tenancy\Domain\CompanyStatus;
use Carbon\CarbonImmutable;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant (FR-TEN-02, FR-TEN-03). Isolasi pada tabel ini memakai RLS kolom id.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $npwp
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $city
 * @property string|null $province
 * @property string|null $postal_code
 * @property string $timezone
 * @property string $currency
 * @property CompanyStatus $status
 * @property string|null $plan_id
 * @property CarbonImmutable|null $subscription_ends_at
 * @property CarbonImmutable|null $suspended_at
 * @property string|null $suspension_reason
 * @property bool $allow_support_access
 * @property array<string, mixed> $settings
 * @property-read Plan|null $plan
 */
class Company extends Model implements HasName
{
    use Auditable;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'legal_name', 'npwp', 'email', 'phone', 'address', 'city', 'province',
        'postal_code', 'timezone', 'currency', 'logo_path', 'allow_support_access',
    ];

    protected $attributes = [
        'settings' => '{}',
        'status' => 'trial',
        'timezone' => 'Asia/Jakarta',
        'currency' => 'IDR',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'subscription_ends_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'allow_support_access' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<Brand, $this> */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    /** @return HasMany<Outlet, $this> */
    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<CompanyUser, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    /** Nama relasi yang dipakai Filament untuk tenancy resource Staf. */
    /** @return HasMany<CompanyUser, $this> */
    public function companyUsers(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    /** @return HasMany<MenuCategory, $this> */
    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class);
    }

    /** @return HasMany<Item, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /** @return HasMany<ModifierGroup, $this> */
    public function modifierGroups(): HasMany
    {
        return $this->hasMany(ModifierGroup::class);
    }

    /** @return HasMany<Promotion, $this> */
    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    /** @return HasMany<KitchenStation, $this> */
    public function kitchenStations(): HasMany
    {
        return $this->hasMany(KitchenStation::class);
    }

    /** @return HasMany<SalesChannel, $this> */
    public function salesChannels(): HasMany
    {
        return $this->hasMany(SalesChannel::class);
    }

    /** @return HasMany<Ingredient, $this> */
    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }

    /** @return HasMany<Supplier, $this> */
    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_users')->withPivot(['id', 'is_active']);
    }

    /**
     * Mode baca-saja bila langganan lewat masa tenggang (FR-TEN-08).
     */
    public function isReadOnly(): bool
    {
        if ($this->status === CompanyStatus::Suspended) {
            return true;
        }

        if ($this->subscription_ends_at === null) {
            return false;
        }

        $graceEnds = $this->subscription_ends_at->addDays((int) config('fnb.subscription.grace_days'));

        return CarbonImmutable::now()->greaterThan($graceEnds);
    }

    public function isAccessible(): bool
    {
        return $this->status !== CompanyStatus::Suspended && ! $this->trashed();
    }
}
