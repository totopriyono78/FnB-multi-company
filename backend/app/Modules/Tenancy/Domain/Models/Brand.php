<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Modules\Audit\Application\Auditable;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string|null $logo_path
 * @property bool $is_active
 */
class Brand extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasUuids;
    use SoftDeletes;
    use TracksAuthor;

    protected $fillable = ['code', 'name', 'logo_path', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Outlet, $this> */
    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class);
    }
}
