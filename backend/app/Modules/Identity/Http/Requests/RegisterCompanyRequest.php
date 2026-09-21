<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** FR-TEN-01 */
class RegisterCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:/^62\d{8,13}$/', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'accept_terms' => ['accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'phone' => $this->filled('phone') ? (PhoneNumber::normalize((string) $this->input('phone')) ?? $this->input('phone')) : null,
        ]);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'company_name' => 'nama usaha',
            'name' => 'nama lengkap',
            'phone' => 'nomor HP',
            'accept_terms' => 'syarat & ketentuan',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.regex' => 'Nomor HP tidak valid. Contoh: 0812 3456 7890.'];
    }
}
