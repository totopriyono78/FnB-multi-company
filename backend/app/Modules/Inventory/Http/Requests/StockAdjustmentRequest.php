<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Domain\Models\StockAdjustment;
use Illuminate\Validation\Rule;

/** FR-INV-05: penyesuaian & waste */
class StockAdjustmentRequest extends InventoryRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid'],
            'type' => ['required', Rule::in(array_keys(StockAdjustment::TYPES))],
            'reason_code' => ['required', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:300'],
            'occurred_at' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.ingredient_id' => ['required', 'uuid'],
            'lines.*.qty' => self::signedQty(),
            'lines.*.unit_cost' => self::cost(),
            'lines.*.note' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['location_id' => 'lokasi', 'reason_code' => 'alasan', 'lines.*.qty' => 'jumlah', 'lines.*.unit_cost' => 'harga pokok'];
    }
}
