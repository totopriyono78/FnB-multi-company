<?php

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-TEN-05, SRS §12.2 */
class OutletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Outlet|null $outlet */
        $outlet = $this->route('outlet');
        $companyId = app(TenantContext::class)->companyId();
        $required = $outlet === null ? 'required' : 'sometimes';

        return [
            'brand_id' => [$required, 'uuid', Rule::exists('brands', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'code' => [$required, 'string', 'max:10', 'regex:/^[A-Z0-9]+$/',
                Rule::unique('outlets', 'code')->where('company_id', $companyId)->ignore($outlet?->id)],
            'name' => [$required, 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:80'],
            'postal_code' => ['nullable', 'string', 'regex:/^\d{5}$/'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:20'],
            'timezone' => ['sometimes', 'string', Rule::in(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'])],
            'opening_hours' => ['sometimes', 'array'],
            'opening_hours.*.open' => ['required_with:opening_hours', 'date_format:H:i'],
            'opening_hours.*.close' => ['required_with:opening_hours', 'date_format:H:i'],
            'business_day_cutoff' => ['sometimes', 'date_format:H:i'],
            'tax_name' => ['sometimes', 'string', 'max:20'],
            'tax_rate' => ['sometimes', 'decimal:0,2', 'min:0', 'max:100'],
            'tax_inclusive' => ['sometimes', 'boolean'],
            'tax_on_service_charge' => ['sometimes', 'boolean'],
            'service_charge_rate' => ['sometimes', 'decimal:0,2', 'min:0', 'max:100'],
            'rounding_unit' => ['sometimes', 'integer', Rule::in([0, 50, 100, 500, 1000])],
            'rounding_mode' => ['sometimes', Rule::in(Outlet::ROUNDING_MODES)],
            'order_mode' => ['sometimes', Rule::in(Outlet::ORDER_MODES)],
            'stock_deduction_trigger' => ['sometimes', Rule::in(Outlet::STOCK_TRIGGERS)],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'npwpd' => ['nullable', 'string', 'max:30'],
            'receipt_settings' => ['sometimes', 'array'],
            'receipt_settings.header' => ['nullable', 'string', 'max:200'],
            'receipt_settings.footer' => ['nullable', 'string', 'max:200'],
            'receipt_settings.show_logo' => ['sometimes', 'boolean'],
            'receipt_settings.paper_width' => ['sometimes', Rule::in([58, 80])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'brand_id' => 'brand',
            'code' => 'kode outlet',
            'name' => 'nama outlet',
            'tax_rate' => 'tarif pajak',
            'service_charge_rate' => 'tarif service charge',
            'postal_code' => 'kode pos',
        ];
    }
}
