<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Domain\Models\KitchenStation;
use Illuminate\Validation\Rule;

/** FR-MENU-14 */
class StationRequest extends CatalogRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var KitchenStation|null $station */
        $station = $this->route('station');
        $req = $station ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:20', 'regex:/^[A-Z0-9_]+$/', Rule::unique('kitchen_stations', 'code')->where('company_id', $this->companyId())->ignore($station?->id)],
            'name' => [$req, 'string', 'max:60'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:32767'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => mb_strtoupper(trim((string) $this->input('code')))]);
        }
    }
}
