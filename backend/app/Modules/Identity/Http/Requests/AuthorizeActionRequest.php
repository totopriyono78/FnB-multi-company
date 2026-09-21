<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-AUTH-07 */
class AuthorizeActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(array_keys(PermissionRegistry::SUPERVISOR_ACTIONS))],
            'supervisor_id' => ['required', 'uuid'],
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
            'reason' => [Rule::requiredIf(in_array($this->input('action'), ['void', 'refund', 'price_override', 'open_drawer'], true)), 'nullable', 'string', 'max:200'],
            'reference_type' => ['nullable', 'string', 'max:40'],
            'reference_id' => ['nullable', 'uuid'],
            'amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'discount_percent' => ['nullable', 'decimal:0,2', 'min:0', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['action' => 'jenis aksi', 'reason' => 'alasan'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['pin.regex' => 'PIN harus 4–6 digit angka.', 'reason.required' => 'Alasan wajib diisi untuk aksi ini.'];
    }
}
