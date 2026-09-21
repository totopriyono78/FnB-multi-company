<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Validation\Rule;

/** FR-MENU-06, BR-01 */
class ChannelRequest extends CatalogRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $channel = $this->route('channel');
        $req = $channel ? 'sometimes' : 'required';

        return [
            'code' => [$channel ? 'prohibited' : 'required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/', Rule::unique('sales_channels', 'code')->where('company_id', $this->companyId())],
            'name' => [$req, 'string', 'max:60'],
            'type' => [$channel ? 'prohibited' : 'required', Rule::in(['in_store', 'aggregator', 'online'])],
            'service_charge_applies' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:32767'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
