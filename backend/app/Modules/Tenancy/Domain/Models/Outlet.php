<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Modules\Audit\Application\Auditable;
use App\Modules\Payment\Domain\Models\OutletPaymentMethod;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Outlet beserta konfigurasi operasional (FR-TEN-05, SRS §12.2).
 *
 * @property string $id
 * @property string $company_id
 * @property string $brand_id
 * @property string $code
 * @property string $name
 * @property string|null $address
 * @property string|null $city
 * @property string|null $province
 * @property string|null $postal_code
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $phone
 * @property string $timezone
 * @property array<string, mixed> $opening_hours
 * @property string $business_day_cutoff
 * @property string $tax_name
 * @property string $tax_rate
 * @property bool $tax_inclusive
 * @property bool $tax_on_service_charge
 * @property string $service_charge_rate
 * @property int $rounding_unit
 * @property string $rounding_mode
 * @property string $order_mode
 * @property string $stock_deduction_trigger
 * @property bool $allow_negative_stock
 * @property string|null $npwpd
 * @property array<string, mixed> $receipt_settings
 * @property bool $is_active
 * @property-read Brand $brand
 * @property-read Collection<int, OutletPaymentMethod> $paymentMethods
 */
class Outlet extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasUuids;
    use SoftDeletes;
    use TracksAuthor;

    public const ORDER_MODES = ['quick_service', 'dine_in'];

    public const STOCK_TRIGGERS = ['on_payment', 'on_kitchen'];

    public const ROUNDING_MODES = ['nearest', 'up', 'down'];

    protected $fillable = [
        'brand_id', 'code', 'name', 'address', 'city', 'province', 'postal_code', 'latitude', 'longitude',
        'phone', 'timezone', 'opening_hours', 'business_day_cutoff', 'tax_name', 'tax_rate', 'tax_inclusive',
        'tax_on_service_charge', 'service_charge_rate', 'rounding_unit', 'rounding_mode', 'order_mode', 'table_count',
        'stock_deduction_trigger', 'allow_negative_stock', 'npwpd', 'receipt_settings', 'is_active',
    ];

    protected $attributes = [
        'opening_hours' => '{}',
        'receipt_settings' => '{}',
        'is_active' => true,
        'rounding_unit' => 100,
        'rounding_mode' => 'nearest',
    ];

    protected function casts(): array
    {
        return [
            'opening_hours' => 'array',
            'receipt_settings' => 'array',
            'tax_rate' => 'decimal:2',
            'service_charge_rate' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'tax_inclusive' => 'boolean',
            'tax_on_service_charge' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'is_active' => 'boolean',
            'rounding_unit' => 'integer',
            'table_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<OutletPaymentMethod, $this> */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(OutletPaymentMethod::class)->orderBy('sort_order');
    }
}
