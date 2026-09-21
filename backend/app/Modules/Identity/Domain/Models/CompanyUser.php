<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Audit\Application\Auditable;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Concerns\TracksAuthor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Keanggotaan user di sebuah company, termasuk PIN kasir (FR-AUTH-03).
 *
 * @property string $id
 * @property string $company_id
 * @property string $user_id
 * @property string|null $employee_code
 * @property string|null $pin_hash
 * @property CarbonImmutable|null $invited_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $sessions_revoked_at
 * @property int $pin_failed_attempts
 * @property CarbonImmutable|null $pin_locked_until
 * @property bool $is_active
 * @property-read User $user
 */
class CompanyUser extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasUuids;
    use TracksAuthor;

    protected $fillable = ['user_id', 'employee_code', 'is_active'];

    protected $hidden = ['pin_hash'];

    protected $attributes = [
        'is_active' => true,
        'pin_failed_attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pin_locked_until' => 'immutable_datetime',
            'invited_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'sessions_revoked_at' => 'immutable_datetime',
            'pin_failed_attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<RoleScope, $this> */
    public function scopes(): HasMany
    {
        return $this->hasMany(RoleScope::class);
    }

    public function isPendingInvitation(): bool
    {
        return $this->invited_at !== null && $this->accepted_at === null;
    }

    public function hasPin(): bool
    {
        return $this->pin_hash !== null;
    }

    public function isPinLocked(): bool
    {
        return $this->pin_locked_until !== null && $this->pin_locked_until->isFuture();
    }
}
