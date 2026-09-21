<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Identity\Domain\Models\PersonalAccessToken;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Domain\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menetapkan company aktif dari token (device/kasir) atau header X-Company-Id (back-office/owner app).
 */
class ResolveCompany
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $token = $actor?->currentAccessToken();
        $boundCompany = $token instanceof PersonalAccessToken ? $token->company_id : null;
        $boundDevice = $token instanceof PersonalAccessToken ? $token->device_id : null;

        $companyId = $boundCompany ?? (string) $request->header('X-Company-Id', '');

        if ($boundCompany !== null && $request->hasHeader('X-Company-Id') && $request->header('X-Company-Id') !== $boundCompany) {
            return $this->deny(403, 'COMPANY_MISMATCH', 'Token ini hanya berlaku untuk company yang terdaftar.');
        }

        if ($companyId === '' || ! Str::isUuid($companyId)) {
            return $this->deny(400, 'COMPANY_REQUIRED', 'Pilih company terlebih dahulu (header X-Company-Id).');
        }

        $company = $this->context->runAsSystem(fn () => Company::query()->find($companyId));
        if ($company === null || ! $company->isAccessible()) {
            return $this->deny(403, 'COMPANY_UNAVAILABLE', 'Company tidak ditemukan atau sedang ditangguhkan.');
        }

        if ($actor instanceof User) {
            $membership = $actor->membershipFor($company->id);
            if ($membership === null) {
                return $this->deny(403, 'NOT_A_MEMBER', 'Anda tidak memiliki akses ke company ini.');
            }

            $issuedAt = $token instanceof PersonalAccessToken ? $token->created_at : null;
            if ($membership->sessions_revoked_at !== null && ($issuedAt === null || $issuedAt->lessThanOrEqualTo($membership->sessions_revoked_at))) {
                return $this->deny(401, 'SESSION_REVOKED', 'Sesi Anda untuk company ini sudah diakhiri. Silakan login kembali.');
            }
        }

        $this->context->setTenant($company->id);
        $request->attributes->set('company', $company);

        if ($boundDevice !== null) {
            $device = $actor instanceof Device ? $actor : Device::query()->find($boundDevice);
            if ($device === null || ! $device->isActive()) {
                return $this->deny(403, 'DEVICE_REVOKED', 'Perangkat ini sudah dinonaktifkan. Data lokal akan dihapus.');
            }
            $request->attributes->set('device', $device);
            $request->attributes->set('device_id', $device->id);
            if ($actor instanceof User) {
                $request->attributes->set('pos_user_id', $actor->id);
            }
        }

        return $next($request);
    }

    private function deny(int $status, string $code, string $message): Response
    {
        return ApiResponse::error($status, [['code' => $code, 'message' => $message]]);
    }
}
