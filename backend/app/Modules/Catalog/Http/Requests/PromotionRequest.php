<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Application\PromotionRules;
use App\Modules\Catalog\Domain\Models\Promotion;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** FR-MENU-11, FR-MENU-12 */
class PromotionRequest extends CatalogRequest
{
    public const PAYMENT_METHODS = ['cash', 'qris', 'debit', 'credit', 'ewallet', 'transfer', 'voucher', 'member_balance', 'city_ledger'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Promotion|null $promo */
        $promo = $this->route('promotion');
        $req = $promo ? 'sometimes' : 'required';

        return [
            'brand_id' => [$promo ? 'prohibited' : 'nullable', 'uuid', $this->existsInCompany('brands', true)],
            'name' => [$req, 'string', 'max:80'],
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Z0-9\-]+$/',
                Rule::unique('promotions', 'code')->where('company_id', $this->companyId())->whereNull('deleted_at')->ignore($promo?->id)],
            'type' => [$req, Rule::in(Promotion::TYPES)],
            'scope' => ['sometimes', Rule::in(Promotion::SCOPES)],
            'value' => ['sometimes', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'min_purchase' => self::money(false),
            'max_discount' => self::money(false),
            'buy_qty' => ['nullable', 'integer', 'min:1', 'max:100'],
            'get_qty' => ['nullable', 'integer', 'min:1', 'max:100'],
            'item_ids' => ['sometimes', 'array', 'max:500'],
            'item_ids.*' => ['uuid', 'distinct', Rule::exists('items', 'id')->where('company_id', $this->companyId())->whereNull('deleted_at')],
            'category_ids' => ['sometimes', 'array', 'max:100'],
            'category_ids.*' => ['uuid', 'distinct', Rule::exists('menu_categories', 'id')->where('company_id', $this->companyId())->whereNull('deleted_at')],
            'outlet_ids' => ['sometimes', 'array'],
            'outlet_ids.*' => ['uuid', 'distinct', $this->existsInCompany('outlets', true)],
            'channel_codes' => ['nullable', 'array'],
            'channel_codes.*' => ['string', Rule::exists('sales_channels', 'code')->where('company_id', $this->companyId())],
            'payment_methods' => ['nullable', 'array'],
            'payment_methods.*' => [Rule::in(self::PAYMENT_METHODS)],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'between:1,7', 'distinct'],
            'time_start' => ['nullable', 'date_format:H:i', 'required_with:time_end'],
            'time_end' => ['nullable', 'date_format:H:i', 'required_with:time_start', 'different:time_start'],
            'starts_at' => [$req, 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'quota' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'stackable' => ['sometimes', 'boolean'],
            'auto_apply' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'between:-100,100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $v): void {
            /** @var Promotion|null $promo */
            $promo = $this->route('promotion');
            $get = fn (string $k) => $this->has($k) ? $this->input($k) : $promo?->getAttribute($k);
            $countTargets = fn (string $key, string $type): int => $this->has($key)
                ? count($this->arrayInput($key))
                : ($promo?->targets->where('target_type', $type)->count() ?? 0);
            $attributes = [];
            foreach (['type', 'scope', 'value', 'buy_qty', 'get_qty', 'auto_apply', 'code'] as $key) {
                $attributes[$key] = $get($key);
            }
            $errors = PromotionRules::errors($attributes, $countTargets('item_ids', 'item') + $countTargets('category_ids', 'category'));
            foreach ($errors as $field => $message) {
                $v->errors()->add($field, $message);
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => mb_strtoupper(trim((string) $this->input('code')))]);
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nama promo', 'code' => 'kode promo', 'type' => 'jenis promo', 'value' => 'nilai promo',
            'min_purchase' => 'minimal belanja', 'max_discount' => 'maksimal potongan', 'starts_at' => 'tanggal mulai',
            'ends_at' => 'tanggal berakhir', 'quota' => 'kuota', 'time_start' => 'jam mulai', 'time_end' => 'jam selesai',
        ];
    }
}
