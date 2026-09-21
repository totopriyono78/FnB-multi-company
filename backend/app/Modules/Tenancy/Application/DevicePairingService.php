<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\Domain\DeviceStatus;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\DevicePairingCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Pairing device ke outlet dengan kode sekali pakai (FR-DEV-01) dan penonaktifan jarak jauh (FR-DEV-07).
 */
class DevicePairingService
{
    /** Tanpa huruf/angka yang mirip (0/O, 1/I/L) agar mudah diketik di tablet. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{code: string, expires_at: CarbonImmutable} */
    public function issueCode(Device $device): array
    {
        if ($device->status === DeviceStatus::Revoked) {
            throw ValidationException::withMessages(['device' => 'Perangkat sudah dinonaktifkan. Daftarkan perangkat baru.']);
        }

        $code = $this->generate();
        $expires = now()->toImmutable()->addMinutes((int) config('fnb.devices.pairing_code_ttl_minutes'));

        DB::transaction(function () use ($device, $code, $expires): void {
            DevicePairingCode::query()->where('device_id', $device->id)->whereNull('used_at')
                ->update(['used_at' => now()]);

            $pairing = new DevicePairingCode;
            $pairing->forceFill([
                'device_id' => $device->id,
                'code_hash' => self::hash($code),
                'expires_at' => $expires,
                'created_by' => auth()->id(),
            ])->save();
        });

        $this->audit->log('device.pairing_code_issued', $device);

        return ['code' => $code, 'expires_at' => $expires];
    }

    /**
     * @param  array{platform?: string|null, app_version?: string|null}  $info
     * @return array{device: Device, token: NewAccessToken}
     */
    public function pair(string $code, array $info): array
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        /** @var DevicePairingCode|null $pairing */
        $pairing = $this->context->runAsSystem(
            fn () => DevicePairingCode::query()->with('device')->where('code_hash', self::hash($normalized))->first()
        );

        if ($pairing === null || $pairing->used_at !== null || $pairing->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'code' => 'Kode pairing tidak berlaku. Minta kode baru dari back-office.',
            ]);
        }

        return $this->context->runAsTenant($pairing->company_id, function () use ($pairing, $info): array {
            return DB::transaction(function () use ($pairing, $info): array {
                $pairing->forceFill(['used_at' => now()])->save();

                $device = $pairing->device;
                if ($device->status === DeviceStatus::Revoked) {
                    throw ValidationException::withMessages(['code' => 'Perangkat sudah dinonaktifkan.']);
                }

                $device->tokens()->delete();
                $device->forceFill([
                    'status' => DeviceStatus::Active,
                    'platform' => $info['platform'] ?? null,
                    'app_version' => $info['app_version'] ?? null,
                    'paired_at' => now(),
                    'last_seen_at' => now(),
                    'wipe_requested_at' => null,
                ])->save();

                $token = $device->createToken('device:'.$device->code, ['device']);
                $token->accessToken->forceFill([
                    'company_id' => $device->company_id,
                    'device_id' => $device->id,
                    'expires_at' => null,
                ])->save();

                $this->audit->log('device.paired', $device, metadata: $info);
                $device->load('outlet.brand');

                return ['device' => $device, 'token' => $token];
            });
        });
    }

    public function revoke(Device $device, ?string $reason): void
    {
        DB::transaction(function () use ($device, $reason): void {
            $device->forceFill([
                'status' => DeviceStatus::Revoked,
                'revoked_at' => now(),
                'wipe_requested_at' => now(),
            ])->save();
            // Token device dipertahankan agar aplikasi menerima status DEVICE_REVOKED dan menghapus data lokal.
            // Token kasir yang terikat ke device ini dicabut.
            DB::table('personal_access_tokens')
                ->where('device_id', $device->id)
                ->where('tokenable_type', '!=', $device->getMorphClass())
                ->delete();

            $this->audit->log('device.revoked', $device, reason: $reason);
        });
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function generate(): string
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
