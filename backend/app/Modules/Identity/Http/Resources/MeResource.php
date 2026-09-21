<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class MeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'is_platform_admin' => $this->is_platform_admin,
            'email_verified' => $this->email_verified_at !== null,
            'companies' => $this->accessibleCompanies()->map(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->status->value,
            ])->values(),
        ];
    }
}
