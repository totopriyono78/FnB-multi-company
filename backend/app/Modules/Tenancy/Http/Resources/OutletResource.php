<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Outlet */
class OutletResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'brand_id' => $this->brand_id,
            'brand' => new BrandResource($this->whenLoaded('brand')),
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postal_code,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'phone' => $this->phone,
            'timezone' => $this->timezone,
            'opening_hours' => (object) $this->opening_hours,
            'business_day_cutoff' => substr($this->business_day_cutoff, 0, 5),
            'tax' => [
                'name' => $this->tax_name,
                'rate' => $this->tax_rate,
                'inclusive' => $this->tax_inclusive,
                'on_service_charge' => $this->tax_on_service_charge,
            ],
            'service_charge_rate' => $this->service_charge_rate,
            'rounding' => ['unit' => $this->rounding_unit, 'mode' => $this->rounding_mode],
            'order_mode' => $this->order_mode,
            'stock_deduction_trigger' => $this->stock_deduction_trigger,
            'allow_negative_stock' => $this->allow_negative_stock,
            'npwpd' => $this->npwpd,
            'receipt_settings' => (object) $this->receipt_settings,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
