<?php

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** FR-DEV-02, FR-DEV-06 */
class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'app_version' => ['nullable', 'string', 'max:20', 'regex:/^\d+\.\d+\.\d+([\-+][0-9A-Za-z.]+)?$/'],
            'pending_sync_count' => ['required', 'integer', 'min:0', 'max:1000000'],
            'last_synced_at' => ['nullable', 'date'],
        ];
    }
}
