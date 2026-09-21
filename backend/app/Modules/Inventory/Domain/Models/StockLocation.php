<?php

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Audit\Application\Auditable;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gudang / lokasi stok per outlet (FR-INV-02).
 *
 * @property string $id
 * @property string $company_id
 * @property string $outlet_id
 * @property string $code
 * @property string $name
 * @property string|null $kitchen_station_id
 * @property bool $is_default
 * @property bool $is_active
 * @property-read Outlet $outlet
 */
class StockLocation extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasUuids;

    protected $fillable = ['outlet_id', 'code', 'name', 'kitchen_station_id', 'is_default', 'is_active'];

    protected $attributes = ['is_default' => false, 'is_active' => true];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class)->withTrashed();
    }

    public function label(): string
    {
        return $this->outlet->name.' · '.$this->name;
    }
}
