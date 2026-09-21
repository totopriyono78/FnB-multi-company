<?php

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PairDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:8', 'max:12'],
            'platform' => ['nullable', 'string', 'in:android,ios,windows,web'],
            'app_version' => ['nullable', 'string', 'max:20', 'regex:/^\d+\.\d+\.\d+([\-+][0-9A-Za-z.]+)?$/'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['code' => 'kode pairing', 'app_version' => 'versi aplikasi'];
    }
}
