<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Batas cakupan akses user ke brand/outlet tertentu (FR-AUTH-06).
 *
 * @property string $id
 * @property string $company_id
 * @property string $company_user_id
 * @property string $scope_type
 * @property string $scope_id
 */
class RoleScope extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const BRAND = 'brand';

    public const OUTLET = 'outlet';

    public const UPDATED_AT = null;

    protected $fillable = ['company_user_id', 'scope_type', 'scope_id'];
}
