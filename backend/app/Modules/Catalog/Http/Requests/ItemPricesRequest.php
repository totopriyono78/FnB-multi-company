<?php

namespace App\Modules\Catalog\Http\Requests;

use Closure;
use Illuminate\Validation\Validator;

/** FR-MENU-06: ganti seluruh daftar harga khusus sebuah item. */
class ItemPricesRequest extends CatalogRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'prices' => ['present', 'array', 'max:500'],
            'prices.*.item_variant_id' => ['nullable', 'uuid'],
            'prices.*.outlet_id' => ['nullable', 'uuid', 'required_without:prices.*.sales_channel_id', $this->existsInCompany('outlets', true)],
            'prices.*.sales_channel_id' => ['nullable', 'uuid', 'required_without:prices.*.outlet_id', $this->existsInCompany('sales_channels')],
            'prices.*.price' => self::money(),
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $v): void {
            $keys = collect($this->arrayInput('prices'))->map(fn ($p) => ($p['item_variant_id'] ?? '').'|'.($p['outlet_id'] ?? '').'|'.($p['sales_channel_id'] ?? ''));
            if ($keys->duplicates()->isNotEmpty()) {
                $v->errors()->add('prices', 'Ada harga ganda untuk kombinasi varian, outlet, dan channel yang sama.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'prices.*.outlet_id.required_without' => 'Pilih outlet atau channel untuk harga khusus.',
            'prices.*.sales_channel_id.required_without' => 'Pilih outlet atau channel untuk harga khusus.',
        ];
    }
}
