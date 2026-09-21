<?php

namespace App\Modules\Tenancy\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Paket langganan platform (FR-TEN-06).
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property int|null $max_outlets
 * @property int|null $max_devices
 * @property int|null $max_users
 * @property list<string> $modules
 * @property string $price_per_outlet_month
 * @property bool $is_active
 */
class Plan extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'name', 'max_outlets', 'max_devices', 'max_users', 'modules', 'price_per_outlet_month', 'is_active'];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'price_per_outlet_month' => 'decimal:2',
            'is_active' => 'boolean',
            'max_outlets' => 'integer',
            'max_devices' => 'integer',
            'max_users' => 'integer',
        ];
    }
}
