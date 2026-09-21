<?php

namespace App\Modules\Inventory\Http\Requests;

/** FR-INV-05: transfer antar gudang/outlet */
class StockTransferRequest extends InventoryRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->routeIs('*.receive') || str_ends_with($this->path(), '/receive')) {
            return [
                'note' => ['nullable', 'string', 'max:300'],
                'lines' => ['sometimes', 'array', 'max:200'],
                'lines.*.line_id' => ['required', 'uuid', 'distinct'],
                'lines.*.qty_received' => self::qty(true, true),
            ];
        }

        return [
            'from_location_id' => ['required', 'uuid'],
            'to_location_id' => ['required', 'uuid', 'different:from_location_id'],
            'notes' => ['nullable', 'string', 'max:300'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.ingredient_id' => ['required', 'uuid'],
            'lines.*.qty' => self::qty(),
            'lines.*.note' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['from_location_id' => 'lokasi asal', 'to_location_id' => 'lokasi tujuan', 'lines.*.qty' => 'jumlah kirim', 'lines.*.qty_received' => 'jumlah diterima'];
    }
}
