<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\PhoneNumber;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var CompanyUser|null $member */
        $member = $this->route('member');
        $companyId = app(TenantContext::class)->companyId();
        $creating = $member === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'email' => [$creating ? 'required' : 'prohibited', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^62\d{8,13}$/'],
            'employee_code' => ['nullable', 'string', 'max:20',
                Rule::unique('company_users', 'employee_code')->where('company_id', $companyId)->ignore($member?->id)],
            'roles' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('company_id', $companyId)],
            'scopes' => ['sometimes', 'array'],
            'scopes.brands' => ['sometimes', 'array'],
            'scopes.brands.*' => ['uuid', Rule::exists('brands', 'id')->where('company_id', $companyId)],
            'scopes.outlets' => ['sometimes', 'array'],
            'scopes.outlets.*' => ['uuid', Rule::exists('outlets', 'id')->where('company_id', $companyId)],
            'pin' => ['sometimes', 'string', 'regex:/^\d{4,6}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('email')) {
            $merge['email'] = mb_strtolower(trim((string) $this->input('email')));
        }
        if ($this->filled('phone')) {
            $merge['phone'] = PhoneNumber::normalize((string) $this->input('phone')) ?? $this->input('phone');
        }
        $this->merge($merge);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nama', 'phone' => 'nomor HP', 'employee_code' => 'kode karyawan', 'roles' => 'role'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'pin.regex' => 'PIN harus 4–6 digit angka.',
            'phone.regex' => 'Nomor HP tidak valid. Contoh: 0812 3456 7890.',
        ];
    }
}
