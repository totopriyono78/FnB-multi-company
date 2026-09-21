<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Domain\Models\StockLocation;

/** FR-INV-02 */
class StockLocationRequest extends InventoryRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var StockLocation|null $location */
        $location = $this->route('location');
        $req = $location ? 'sometimes' : 'required';

        return [
            'outlet_id' => [$location ? 'prohibited' : 'required', 'uuid', $this->existsInCompany('outlets', true)],
            'code' => [$req, 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => [$req, 'string', 'max:60'],
            'kitchen_station_id' => ['nullable', 'uuid', $this->existsInCompany('kitchen_stations')],
            'is_default' => ['sometimes', 'accepted'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['code' => 'kode lokasi', 'name' => 'nama lokasi', 'kitchen_station_id' => 'stasiun dapur'];
    }
}
