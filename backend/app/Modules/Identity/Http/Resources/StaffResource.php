<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\RoleScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CompanyUser */
class StaffResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $scopes = $this->whenLoaded('scopes', fn () => [
            'brands' => $this->scopes->where('scope_type', RoleScope::BRAND)->pluck('scope_id')->values(),
            'outlets' => $this->scopes->where('scope_type', RoleScope::OUTLET)->pluck('scope_id')->values(),
        ]);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'phone' => $this->user->phone,
            'employee_code' => $this->employee_code,
            'roles' => $this->user->relationLoaded('roles') ? $this->user->roles->pluck('name')->values() : [],
            'scopes' => $scopes,
            'has_pin' => $this->hasPin(),
            'pin_locked' => $this->isPinLocked(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
