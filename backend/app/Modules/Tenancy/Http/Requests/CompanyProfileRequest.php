<?php

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-TEN-03 */
class CompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:150'],
            'npwp' => ['nullable', 'string', 'regex:/^(\d{15}|\d{16}|\d{2}\.\d{3}\.\d{3}\.\d-\d{3}\.\d{3})$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:80'],
            'postal_code' => ['nullable', 'string', 'regex:/^\d{5}$/'],
            'timezone' => ['sometimes', Rule::in(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'])],
            'currency' => ['sometimes', Rule::in(['IDR'])],
            'allow_support_access' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nama company', 'npwp' => 'NPWP', 'postal_code' => 'kode pos', 'timezone' => 'zona waktu'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['npwp.regex' => 'Format NPWP tidak valid (15/16 digit).'];
    }
}
