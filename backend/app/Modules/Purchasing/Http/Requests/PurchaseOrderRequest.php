<?php

namespace App\Modules\Purchasing\Http\Requests;

use App\Modules\Inventory\Http\Requests\InventoryRequest;

/** Purchase order (FR-PUR) */
class PurchaseOrderRequest extends InventoryRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $update = $this->route('purchaseOrder') !== null;
        $req = $update ? 'sometimes' : 'required';

        return [
            'location_id' => [$update ? 'prohibited' : 'required', 'uuid'],
            'supplier_id' => [$req, 'uuid'],
            'order_date' => [$update ? 'prohibited' : 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'expected_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => [$req, 'array', 'min:1', 'max:200'],
            'lines.*.ingredient_id' => ['required', 'uuid', 'distinct'],
            'lines.*.unit_name' => ['required', 'string', 'max:20'],
            'lines.*.qty' => self::qty(),
            'lines.*.unit_price' => self::money(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'location_id' => 'lokasi tujuan', 'supplier_id' => 'pemasok', 'expected_date' => 'tanggal kirim',
            'lines.*.unit_name' => 'satuan', 'lines.*.qty' => 'jumlah', 'lines.*.unit_price' => 'harga satuan',
        ];
    }
}
