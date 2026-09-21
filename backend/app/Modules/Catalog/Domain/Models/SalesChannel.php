<?php

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Audit\Application\Auditable;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property bool $service_charge_applies
 * @property bool $is_system
 * @property int $sort_order
 * @property bool $is_active
 */
class SalesChannel extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasUuids;

    protected $table = 'sales_channels';

    protected $fillable = ['code', 'name', 'type', 'service_charge_applies', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['service_charge_applies' => 'boolean', 'is_system' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
