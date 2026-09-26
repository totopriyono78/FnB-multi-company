<?php

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Shared\Application\MediaStore;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Brand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-TEN-04 */
class BrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Otorisasi di controller lewat BrandPolicy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Brand|null $brand */
        $brand = $this->route('brand');
        $required = $brand === null ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:20', 'regex:/^[A-Z0-9\-]+$/',
                Rule::unique('brands', 'code')
                    ->where('company_id', app(TenantContext::class)->companyId())
                    ->ignore($brand?->id),
            ],
            'name' => [$required, 'string', 'max:100'],
            // Hanya jalur internal hasil unggahan, bukan URL luar — lihat ItemRequest::rules().
            'logo_path' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:'.MediaStore::PATH_PATTERN],
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
        return ['code' => 'kode brand', 'name' => 'nama brand'];
    }
}
