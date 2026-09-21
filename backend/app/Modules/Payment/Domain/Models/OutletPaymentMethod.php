<?php

namespace App\Modules\Payment\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metode pembayaran aktif per outlet, urutan, dan MDR (FR-PAY-02, FR-PAY-10).
 *
 * @property string $id
 * @property string $company_id
 * @property string $outlet_id
 * @property string $method
 * @property string $label
 * @property bool $is_active
 * @property int $sort_order
 * @property string $mdr_percent
 * @property string $mdr_fixed
 */
class OutletPaymentMethod extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $fillable = ['outlet_id', 'method', 'label', 'is_active', 'sort_order', 'mdr_percent', 'mdr_fixed'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer', 'mdr_percent' => 'decimal:2', 'mdr_fixed' => 'decimal:2'];
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
