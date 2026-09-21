<?php

namespace App\Modules\Identity\Domain\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * @property int $id
 * @property string $name
 * @property string|null $group
 */
class Permission extends SpatiePermission {}
