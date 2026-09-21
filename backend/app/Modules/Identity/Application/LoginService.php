<?php

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Login back-office dengan email/nomor HP + password dan penguncian akun (FR-AUTH-01, FR-AUTH-08).
 */
class LoginService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function attempt(string $login, string $password): User
    {
        $user = $this->context->runAsSystem(fn () => $this->findByLogin($login));

        if ($user === null) {
            // Tetap lakukan hash agar waktu respons tidak membocorkan keberadaan akun.
            Hash::check($password, '$2y$12$'.str_repeat('a', 53));
            throw $this->invalid();
        }

        if ($user->isLocked()) {
            throw ValidationException::withMessages([
                'login' => 'Akun terkunci sementara karena terlalu banyak percobaan. Coba lagi setelah '
                    .$user->locked_until?->timezone(config('app.display_timezone'))->format('H.i').'.',
            ])->status(423);
        }

        if (! Hash::check($password, $user->password)) {
            $this->registerFailure($user);
            throw $this->invalid();
        }

        $this->context->runAsSystem(function () use ($user): void {
            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'last_login_at' => now(),
            ])->save();
        });

        $this->audit->log('auth.login', $user, userId: $user->id, companyId: null);

        return $user;
    }

    public function findByLogin(string $login): ?User
    {
        $login = trim($login);

        if (str_contains($login, '@')) {
            return User::query()->whereRaw('lower(email) = ?', [mb_strtolower($login)])->first();
        }

        $phone = PhoneNumber::normalize($login);

        return $phone === null ? null : User::query()->where('phone', $phone)->first();
    }

    private function registerFailure(User $user): void
    {
        $max = (int) config('fnb.auth.login_max_attempts');

        $this->context->runAsSystem(function () use ($user, $max): void {
            $attempts = $user->failed_login_attempts + 1;
            $user->forceFill([
                'failed_login_attempts' => $attempts,
                'locked_until' => $attempts >= $max
                    ? now()->addMinutes((int) config('fnb.auth.login_lock_minutes'))
                    : null,
            ])->save();

            if ($attempts >= $max) {
                $this->audit->log('auth.account_locked', $user, userId: $user->id, companyId: null);
            }
        });
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages([
            'login' => 'Email/nomor HP atau password salah.',
        ]);
    }
}
