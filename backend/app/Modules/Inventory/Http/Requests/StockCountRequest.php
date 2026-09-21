<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Validation\Rule;

/** FR-INV-06: mulai opname & isi hasil hitung */
class StockCountRequest extends InventoryRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->isMethod('put')) {
            return [
                'lines' => ['required', 'array', 'min:1', 'max:1000'],
                'lines.*.ingredient_id' => ['required', 'uuid', 'distinct'],
                'lines.*.counted_qty' => self::qty(false, true),
                'lines.*.note' => ['nullable', 'string', 'max:200'],
            ];
        }

        return [
            'location_id' => ['required', 'uuid'],
            'scope' => ['required', Rule::in(['full', 'partial'])],
            'ingredient_ids' => ['required_if:scope,partial', 'array', 'max:1000'],
            'ingredient_ids.*' => ['uuid', 'distinct'],
            'notes' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['location_id' => 'lokasi', 'scope' => 'jenis opname', 'ingredient_ids' => 'bahan', 'lines.*.counted_qty' => 'jumlah fisik'];
    }
}
