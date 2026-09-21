<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Domain\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->label,
            'is_system' => $this->is_system,
            'max_discount_percent' => $this->max_discount_percent,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')->sort()->values()),
        ];
    }
}
