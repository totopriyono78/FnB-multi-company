<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Modules\Audit\Application\Auditable;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Add-on modul yang aktif per company (FR-TEN-09).
 *
 * @property string $id
 * @property string $company_id
 * @property string $module
 * @property bool $is_enabled
 */
class CompanyModule extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasUuids;

    protected $fillable = ['module', 'is_enabled'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }
}
