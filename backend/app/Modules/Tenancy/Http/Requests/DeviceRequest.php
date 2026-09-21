<?php

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\DeviceType;
use App\Modules\Tenancy\Domain\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-DEV-01 */
class DeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Device|null $device */
        $device = $this->route('device');
        $companyId = app(TenantContext::class)->companyId();
        $outletId = $device !== null ? $device->outlet_id : $this->input('outlet_id');

        return [
            'outlet_id' => [$device ? 'prohibited' : 'required', 'uuid',
                Rule::exists('outlets', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'code' => [$device ? 'sometimes' : 'required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/',
                Rule::unique('devices', 'code')->where('company_id', $companyId)
                    ->where('outlet_id', is_string($outletId) ? $outletId : null)
                    ->ignore($device?->id)],
            'name' => [$device ? 'sometimes' : 'required', 'string', 'max:60'],
            'type' => [$device ? 'prohibited' : 'required', Rule::enum(DeviceType::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $this->input('code')) ?? '')]);
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['outlet_id' => 'outlet', 'code' => 'kode perangkat', 'name' => 'nama perangkat', 'type' => 'jenis perangkat'];
    }
}
