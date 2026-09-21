<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:60'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['login' => 'email atau nomor HP', 'device_name' => 'nama perangkat'];
    }
}
