<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\PermissionRegistry;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-AUTH-05: role kustom */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Role|null $role */
        $role = $this->route('role');
        $companyId = app(TenantContext::class)->companyId();

        return [
            'name' => [$role ? 'prohibited' : 'required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'name')->where('company_id', $companyId)->where('guard_name', 'web')],
            'label' => [$role ? 'sometimes' : 'required', 'string', 'max:60'],
            'max_discount_percent' => ['sometimes', 'decimal:0,2', 'min:0', 'max:100'],
            'permissions' => [$role ? 'sometimes' : 'required', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionRegistry::all())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.regex' => 'Nama role hanya huruf kecil, angka, dan garis bawah (contoh: kasir_senior).'];
    }
}
