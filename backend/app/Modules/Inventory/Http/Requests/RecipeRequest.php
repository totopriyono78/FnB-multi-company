<?php

namespace App\Modules\Inventory\Http\Requests;

/** FR-INV-03 */
class RecipeRequest extends InventoryRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'yield_qty' => ['sometimes', ...self::qty()],
            'notes' => ['nullable', 'string', 'max:300'],
            'lines' => ['present', 'array', 'max:50'],
            'lines.*.ingredient_id' => ['required', 'uuid', 'distinct'],
            'lines.*.qty' => self::signedQty(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['lines.*.ingredient_id' => 'bahan', 'lines.*.qty' => 'jumlah bahan', 'yield_qty' => 'hasil resep'];
    }
}
