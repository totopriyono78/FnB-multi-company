<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Domain\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'npwp' => $this->npwp,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postal_code,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'allow_support_access' => $this->allow_support_access,
            'read_only' => $this->isReadOnly(),
            'subscription' => [
                'plan' => $this->whenLoaded('plan', fn () => $this->plan ? [
                    'code' => $this->plan->code,
                    'name' => $this->plan->name,
                    'max_outlets' => $this->plan->max_outlets,
                    'max_devices' => $this->plan->max_devices,
                    'max_users' => $this->plan->max_users,
                ] : null),
                'ends_at' => $this->subscription_ends_at?->toIso8601String(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
