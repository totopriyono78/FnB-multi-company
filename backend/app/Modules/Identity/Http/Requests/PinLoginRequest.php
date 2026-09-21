<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** FR-AUTH-03 */
class PinLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'staff_id' => ['required', 'uuid'],
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['pin.regex' => 'PIN harus 4–6 digit angka.'];
    }
}
