<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Tenancy\Domain\DeviceStatus;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Validation\ValidationException;

/** Menegakkan batas paket langganan (FR-TEN-06). */
class PlanLimits
{
    public function __construct(private readonly TenantContext $context) {}

    public function ensureCanAddOutlet(): void
    {
        $limit = $this->company()->plan?->max_outlets;
        if ($limit !== null && Outlet::query()->count() >= $limit) {
            throw ValidationException::withMessages([
                'plan' => "Paket Anda hanya mengizinkan {$limit} outlet. Tingkatkan paket untuk menambah outlet.",
            ])->status(402);
        }
    }

    public function ensureCanAddDevice(): void
    {
        $limit = $this->company()->plan?->max_devices;
        if ($limit !== null && Device::query()->where('status', '!=', DeviceStatus::Revoked->value)->count() >= $limit) {
            throw ValidationException::withMessages([
                'plan' => "Paket Anda hanya mengizinkan {$limit} perangkat aktif. Nonaktifkan perangkat lama atau tingkatkan paket.",
            ])->status(402);
        }
    }

    public function ensureCanAddUser(): void
    {
        $limit = $this->company()->plan?->max_users;
        if ($limit !== null && CompanyUser::query()->where('is_active', true)->count() >= $limit) {
            throw ValidationException::withMessages([
                'plan' => "Paket Anda hanya mengizinkan {$limit} user aktif. Tingkatkan paket untuk menambah user.",
            ])->status(402);
        }
    }

    private function company(): Company
    {
        return Company::query()->with('plan')->findOrFail($this->context->requireCompanyId());
    }
}
