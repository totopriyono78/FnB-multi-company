<?php

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * PIN kasir/supervisor: hash, batas percobaan, dan otorisasi aksi sensitif
 * (FR-AUTH-03, FR-AUTH-07, NFR-SEC-03).
 *
 * PIN tidak diwajibkan unik; supervisor memilih namanya lalu memasukkan PIN,
 * sehingga tidak ada respons yang membocorkan PIN milik staf lain.
 */
class PinService
{
    /** Batas kegagalan otorisasi per perangkat sebelum perangkat ditahan sementara. */
    public const DEVICE_FAILURE_LIMIT = 10;

    public const DEVICE_FAILURE_DECAY_SECONDS = 900;

    private const WEAK_PINS = ['0000', '1111', '1234', '4321', '000000', '111111', '123456', '654321', '121212'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessScope $scope,
    ) {}

    public function setPin(CompanyUser $member, string $pin): void
    {
        $this->assertFormat($pin);

        if (in_array($pin, self::WEAK_PINS, true) || preg_match('/^(\d)\1+$/', $pin)) {
            throw ValidationException::withMessages(['pin' => 'PIN terlalu mudah ditebak. Gunakan kombinasi lain.']);
        }

        $member->forceFill([
            'pin_hash' => Hash::make($pin, ['rounds' => (int) config('fnb.auth.pin_hash_rounds')]),
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ])->saveQuietly();

        $this->audit->log('user.pin_changed', $member);
    }

    /** Login kasir dengan PIN pada device terdaftar. */
    public function verifyLogin(CompanyUser $member, string $pin, Outlet $outlet): void
    {
        if (! $member->is_active || ! $member->hasPin()) {
            throw ValidationException::withMessages(['pin' => 'Akun ini belum bisa masuk ke kasir. Hubungi manajer outlet.']);
        }

        $this->verifyPin($member, $pin, 'PIN salah.');

        /** @var User $user */
        $user = $member->user;
        if (! $user->can('pos.transact') || ! $this->scope->allowsOutlet($user, $outlet)) {
            throw ValidationException::withMessages(['pin' => 'Anda tidak terdaftar sebagai kasir di outlet ini.'])->status(403);
        }
    }

    /**
     * Otorisasi supervisor dengan PIN untuk aksi sensitif (FR-AUTH-07).
     *
     * @param  array<string, mixed>  $context
     */
    public function authorizeAction(string $action, string $supervisorId, string $pin, Device $device, ?string $reason, array $context = []): CompanyUser
    {
        $permission = PermissionRegistry::SUPERVISOR_ACTIONS[$action]
            ?? throw ValidationException::withMessages(['action' => 'Jenis aksi tidak dikenal.']);

        $deviceKey = 'pos-authorize:'.$device->id;
        if (RateLimiter::tooManyAttempts($deviceKey, self::DEVICE_FAILURE_LIMIT)) {
            throw ValidationException::withMessages([
                'pin' => 'Terlalu banyak percobaan otorisasi gagal di perangkat ini. Coba lagi dalam beberapa menit.',
            ])->status(429);
        }

        $fail = function (string $cause, ?string $supervisorUserId = null) use ($action, $device, $reason, $context, $deviceKey): ValidationException {
            RateLimiter::hit($deviceKey, self::DEVICE_FAILURE_DECAY_SECONDS);
            $this->audit->log('pos.authorization_failed', $device, reason: $reason, authorizedBy: $supervisorUserId, metadata: [
                'action' => $action, 'outlet_id' => $device->outlet_id, 'cause' => $cause,
            ] + $context);

            // Pesan sama untuk semua penyebab agar tidak membocorkan informasi.
            return ValidationException::withMessages(['pin' => 'Otorisasi ditolak. Periksa nama supervisor dan PIN.'])->status(403);
        };

        $member = CompanyUser::query()
            ->with(['user.roles.permissions', 'user.permissions'])
            ->where('is_active', true)
            ->find($supervisorId);

        if ($member === null || ! $member->hasPin()) {
            throw $fail('supervisor_not_found');
        }

        try {
            $this->verifyPin($member, $pin, 'Otorisasi ditolak. Periksa nama supervisor dan PIN.', 403);
        } catch (ValidationException $e) {
            if ($e->status === 423) {
                throw $e;
            }
            throw $fail('wrong_pin', $member->user_id);
        }

        /** @var User $supervisor */
        $supervisor = $member->user;
        if (! $supervisor->can($permission) || ! $this->scope->allowsOutlet($supervisor, $device->outlet)) {
            throw $fail('not_permitted', $supervisor->id);
        }

        RateLimiter::clear($deviceKey);

        return $member;
    }

    private function verifyPin(CompanyUser $member, string $pin, string $wrongMessage, int $wrongStatus = 422): void
    {
        if ($member->isPinLocked()) {
            throw ValidationException::withMessages([
                'pin' => 'PIN terkunci karena terlalu banyak salah. Minta manajer membuka kunci atau tunggu beberapa menit.',
            ])->status(423);
        }

        if (! Hash::check($pin, (string) $member->pin_hash)) {
            $this->registerFailure($member);
            throw ValidationException::withMessages(['pin' => $wrongMessage])->status($wrongStatus);
        }

        $this->resetFailures($member);
    }

    private function registerFailure(CompanyUser $member): void
    {
        $max = (int) config('fnb.auth.pin_max_attempts');
        $attempts = $member->pin_failed_attempts + 1;

        $member->forceFill([
            'pin_failed_attempts' => $attempts,
            'pin_locked_until' => $attempts >= $max ? now()->addMinutes((int) config('fnb.auth.pin_lock_minutes')) : null,
        ])->saveQuietly();

        if ($attempts >= $max) {
            $this->audit->log('user.pin_locked', $member);
        }
    }

    private function resetFailures(CompanyUser $member): void
    {
        if ($member->pin_failed_attempts > 0 || $member->pin_locked_until !== null) {
            $member->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null])->saveQuietly();
        }
    }

    private function assertFormat(string $pin): void
    {
        $min = (int) config('fnb.auth.pin_min_length');
        $max = (int) config('fnb.auth.pin_max_length');

        if (! preg_match('/^\d{'.$min.','.$max.'}$/', $pin)) {
            throw ValidationException::withMessages(['pin' => "PIN harus {$min}–{$max} digit angka."]);
        }
    }
}
