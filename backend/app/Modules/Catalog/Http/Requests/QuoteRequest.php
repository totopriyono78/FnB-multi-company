<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Http\Requests\PromotionRequest as Promo;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** FR-POS-20: simulasi total transaksi. */
class QuoteRequest extends CatalogRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outlet_id' => [$this->routeIs('api.pos.*') ? 'prohibited' : 'required', 'uuid'],
            'channel_code' => ['sometimes', 'string', 'max:30'],
            'payment_method' => ['nullable', Rule::in(Promo::PAYMENT_METHODS)],
            'promo_codes' => ['sometimes', 'array', 'max:5'],
            'promo_codes.*' => ['string', 'max:30'],
            'at' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.id' => ['nullable', 'string', 'max:40', 'distinct'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.variant_id' => ['nullable', 'uuid'],
            'lines.*.qty' => ['required', 'decimal:0,3', 'regex:'.self::PLAIN_DECIMAL, 'gt:0', 'max:9999'],
            'lines.*.note' => ['nullable', 'string', 'max:200'],
            'lines.*.modifiers' => ['sometimes', 'array', 'max:50'],
            'lines.*.modifiers.*.id' => ['required', 'uuid'],
            'lines.*.modifiers.*.qty' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'lines.*.bundle' => ['sometimes', 'array', 'max:10'],
            'lines.*.bundle.*.group_id' => ['required', 'uuid'],
            'lines.*.bundle.*.options' => ['required', 'array', 'max:20'],
            'lines.*.bundle.*.options.*.option_id' => ['required', 'uuid'],
            'lines.*.discounts' => ['sometimes', 'array', 'max:3'],
            'lines.*.discounts.*.type' => ['required', Rule::in(['percent', 'amount'])],
            'lines.*.discounts.*.value' => ['required', 'decimal:0,2', 'regex:'.self::PLAIN_DECIMAL, 'min:0', 'max:9999999999'],
            'order_discounts' => ['sometimes', 'array', 'max:3'],
            'order_discounts.*.type' => ['required', Rule::in(['percent', 'amount'])],
            'order_discounts.*.value' => ['required', 'decimal:0,2', 'regex:'.self::PLAIN_DECIMAL, 'min:0', 'max:9999999999'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $v): void {
            $check = function (mixed $discounts, string $prefix) use ($v): void {
                foreach (is_array($discounts) ? $discounts : [] as $i => $d) {
                    if (is_array($d) && ($d['type'] ?? null) === 'percent' && is_numeric($d['value'] ?? null) && (float) $d['value'] > 100) {
                        $v->errors()->add("{$prefix}.{$i}.value", 'Diskon persen maksimal 100.');
                    }
                }
            };
            foreach ($this->arrayInput('lines') as $l => $line) {
                $check(is_array($line) ? ($line['discounts'] ?? []) : [], "lines.{$l}.discounts");
            }
            $check($this->arrayInput('order_discounts'), 'order_discounts');
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['lines' => 'daftar menu', 'lines.*.qty' => 'jumlah', 'lines.*.item_id' => 'menu'];
    }
}
