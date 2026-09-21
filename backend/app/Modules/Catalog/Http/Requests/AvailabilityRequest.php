<?php

namespace App\Modules\Catalog\Http\Requests;

/** FR-MENU-07, FR-MENU-08 */
class AvailabilityRequest extends CatalogRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outlet_id' => ['required', 'uuid', $this->existsInCompany('outlets', true)],
            'is_listed' => ['sometimes', 'boolean'],
            'is_sold_out' => ['required_without:is_listed', 'boolean'],
        ];
    }
}
