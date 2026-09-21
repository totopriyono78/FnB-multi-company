<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Application\LoginService;
use App\Modules\Identity\Domain\Models\PersonalAccessToken;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RegisterCompanyRequest;
use App\Modules\Identity\Http\Resources\MeResource;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Application\CompanyRegistrar;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/** FR-AUTH-01, FR-AUTH-08, FR-AUTH-10, FR-TEN-01 */
class AuthController extends Controller
{
    public function __construct(
        private readonly LoginService $login,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->login->attempt($request->string('login')->toString(), $request->string('password')->toString());

        $token = $this->context->runAsSystem(function () use ($user, $request) {
            return $user->createToken(
                Str::limit($request->string('device_name')->toString(), 60, ''),
                ['backoffice'],
                now()->addMinutes((int) config('sanctum.expiration')),
            );
        });

        return ApiResponse::ok([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => (new MeResource($user))->resolve($request),
        ]);
    }

    public function register(RegisterCompanyRequest $request, CompanyRegistrar $registrar): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $registrar) {
            $user = $this->context->runAsSystem(fn () => User::query()->create([
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'phone' => $request->input('phone'),
                'password' => $request->string('password')->toString(),
            ]));

            $company = $registrar->register([
                'name' => $request->string('company_name')->toString(),
                'city' => $request->input('city'),
                'email' => $request->string('email')->toString(),
                'phone' => $request->input('phone'),
            ], $user);

            $token = $this->context->runAsSystem(
                fn () => $user->createToken('registrasi', ['backoffice'], now()->addMinutes((int) config('sanctum.expiration')))
            );

            return [$user, $company, $token];
        });

        [$user, $company, $token] = $result;
        event(new Registered($user));

        return ApiResponse::created([
            'token' => $token->plainTextToken,
            'company_id' => $company->id,
            'user' => (new MeResource($user))->resolve($request),
            'next_step' => 'Cek email untuk verifikasi, lalu buat brand dan outlet pertama.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::ok(new MeResource($this->actor($request)));
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $this->actor($request)->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $this->context->runAsSystem(fn () => $token->delete());
        }

        return ApiResponse::ok(['logged_out' => true]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc']]);

        $this->context->runAsSystem(fn () => Password::sendResetLink(['email' => mb_strtolower($data['email'])]));

        // Respons selalu sama agar tidak membocorkan email terdaftar.
        return ApiResponse::ok(['message' => 'Bila email terdaftar, tautan reset password sudah dikirim.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = $this->context->runAsSystem(fn () => Password::reset(
            ['email' => mb_strtolower($data['email']), 'token' => $data['token'], 'password' => $data['password'], 'password_confirmation' => $request->input('password_confirmation')],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
                $this->audit->log('auth.password_reset', $user, userId: $user->id, companyId: null);
            }
        ));

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error(422, [[
                'code' => 'RESET_TOKEN_INVALID',
                'field' => 'token',
                'message' => 'Tautan reset sudah tidak berlaku. Minta tautan baru.',
            ]]);
        }

        return ApiResponse::ok(['message' => 'Password berhasil diganti. Silakan login kembali.']);
    }
}
