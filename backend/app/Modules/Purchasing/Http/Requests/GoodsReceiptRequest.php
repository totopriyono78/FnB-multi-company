<?php

namespace App\Modules\Purchasing\Http\Requests;

use App\Modules\Inventory\Http\Requests\InventoryRequest;

/** Penerimaan barang dari PO atau tanpa PO (FR-INV-05) */
class GoodsReceiptRequest extends InventoryRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $fromPo = $this->route('purchaseOrder') !== null;
        $common = [
            'received_at' => ['nullable', 'date'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:300'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
        ];

        if ($fromPo) {
            return $common + [
                'lines.*.purchase_order_line_id' => ['required', 'uuid', 'distinct'],
                'lines.*.qty' => self::qty(true, true),
                'lines.*.unit_price' => self::money(false),
            ];
        }

        return $common + [
            'location_id' => ['required', 'uuid'],
            'supplier_id' => ['nullable', 'uuid'],
            'lines.*.ingredient_id' => ['required', 'uuid', 'distinct'],
            'lines.*.unit_name' => ['required', 'string', 'max:20'],
            'lines.*.qty' => self::qty(),
            'lines.*.unit_price' => self::money(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['lines.*.qty' => 'jumlah diterima', 'lines.*.unit_price' => 'harga satuan', 'supplier_invoice_no' => 'nomor faktur pemasok'];
    }
}
