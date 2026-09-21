<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Domain\Models\Item;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** FR-MENU-02, 03, 05, 07, 14 */
class ItemRequest extends CatalogRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Item|null $item */
        $item = $this->route('item');
        $req = $item ? 'sometimes' : 'required';
        $brandId = $item->brand_id ?? $this->input('brand_id');

        return [
            'brand_id' => [$item ? 'prohibited' : 'required', 'uuid', $this->existsInCompany('brands', true)],
            'category_id' => [$req, 'uuid'],
            'type' => [$item ? 'prohibited' : 'sometimes', Rule::in([Item::TYPE_SINGLE, Item::TYPE_BUNDLE])],
            'sku' => [$req, 'string', 'max:40', 'regex:/^[A-Za-z0-9\-_.]+$/',
                $this->uniqueInBrand('items', 'sku', is_string($brandId) ? $brandId : null, $item?->id, 'SKU sudah dipakai di brand ini.')],
            'barcode' => ['nullable', 'string', 'max:40'],
            'name' => [$req, 'string', 'max:100'],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:24'],
            'description' => ['nullable', 'string', 'max:1000'],
            'base_price' => $item ? ['sometimes', ...array_slice(self::money(), 1)] : self::money(),
            'kitchen_station_id' => ['nullable', 'uuid'],
            'channel_codes' => ['nullable', 'array'],
            'channel_codes.*' => ['string', Rule::exists('sales_channels', 'code')->where('company_id', $this->companyId())],
            ...self::scheduleRules('schedule'),
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:32767'],
            'is_active' => ['sometimes', 'boolean'],
            'variants' => ['sometimes', 'array', 'max:20'],
            'variants.*.id' => ['nullable', 'uuid'],
            'variants.*.name' => ['required', 'string', 'max:40', 'distinct:ignore_case'],
            'variants.*.sku' => ['nullable', 'string', 'max:40'],
            'variants.*.price' => self::money(),
            'variants.*.is_default' => ['sometimes', 'boolean'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'modifier_group_ids' => ['sometimes', 'array', 'max:20'],
            'modifier_group_ids.*' => ['uuid', 'distinct'],
            'bundle_groups' => ['sometimes', 'array', 'max:10'],
            'bundle_groups.*.name' => ['required', 'string', 'max:60'],
            'bundle_groups.*.min_select' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'bundle_groups.*.max_select' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'bundle_groups.*.options' => ['required', 'array', 'min:1', 'max:50'],
            'bundle_groups.*.options.*.item_id' => ['required', 'uuid'],
            'bundle_groups.*.options.*.item_variant_id' => ['nullable', 'uuid'],
            'bundle_groups.*.options.*.extra_price' => self::money(false),
            'bundle_groups.*.options.*.is_default' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $v): void {
            $defaults = collect($this->arrayInput('variants'))->where('is_default', true)->count();
            if ($defaults > 1) {
                $v->errors()->add('variants', 'Hanya satu varian yang boleh menjadi default.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('channel_codes') && is_array($this->input('channel_codes'))) {
            $this->merge(['channel_codes' => array_values(array_unique($this->input('channel_codes')))]);
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'category_id' => 'kategori', 'sku' => 'SKU', 'name' => 'nama menu', 'short_name' => 'nama singkat',
            'base_price' => 'harga', 'variants.*.name' => 'nama varian', 'variants.*.price' => 'harga varian',
            'channel_codes' => 'channel', 'kitchen_station_id' => 'stasiun dapur',
            'bundle_groups.*.options' => 'pilihan paket',
        ];
    }
}
