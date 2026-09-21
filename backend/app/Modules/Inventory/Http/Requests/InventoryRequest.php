<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Catalog\Http\Requests\CatalogRequest;

/** Dasar Form Request inventory & pembelian. Otorisasi dilakukan di controller/layanan. */
abstract class InventoryRequest extends CatalogRequest
{
    public const SIGNED_DECIMAL = '/^-?\d+(\.\d+)?$/';

    /** @return list<string> kuantitas positif (maks. 4 desimal) */
    protected static function qty(bool $required = true, bool $allowZero = false): array
    {
        return [$required ? 'required' : 'nullable', 'decimal:0,4', 'regex:'.self::PLAIN_DECIMAL, $allowZero ? 'min:0' : 'gt:0', 'max:99999999'];
    }

    /** @return list<string> kuantitas bertanda (penyesuaian) */
    protected static function signedQty(): array
    {
        return ['required', 'decimal:0,4', 'regex:'.self::SIGNED_DECIMAL, 'min:-99999999', 'max:99999999'];
    }

    /** @return list<string> */
    protected static function cost(): array
    {
        return ['nullable', 'decimal:0,6', 'regex:'.self::PLAIN_DECIMAL, 'min:0', 'max:9999999999'];
    }
}
