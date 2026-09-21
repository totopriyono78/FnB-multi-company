<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Application\PermissionRegistry;
use App\Modules\Identity\Application\PinService;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\PersonalAccessToken;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Http\Requests\AuthorizeActionRequest;
use App\Modules\Identity\Http\Requests\PinLoginRequest;
use App\Modules\Sales\Application\Authorizations;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Domain\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Login kasir dengan PIN & otorisasi supervisor di POS (FR-AUTH-03, FR-AUTH-07). */
class PosAuthController extends Controller
{
    public function __construct(
        private readonly PinService $pins,
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /** Daftar staf yang dapat login di device ini (layar pilih nama kasir). */
    public function staff(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $outlet = $device->outlet;

        $members = CompanyUser::query()
            ->with(['user.roles.permissions', 'user.permissions'])
            ->where('is_active', true)
            ->whereNotNull('pin_hash')
            ->orderBy('employee_code')
            ->get();
        $this->scope->prime($members);

        $members = $members
            ->filter(fn (CompanyUser $m) => $m->user->can('pos.transact') && $this->scope->allowsOutlet($m->user, $outlet))
            ->map(fn (CompanyUser $m) => [
                'id' => $m->id,
                'name' => $m->user->name,
                'employee_code' => $m->employee_code,
                'locked' => $m->isPinLocked(),
            ])
            ->values();

        return ApiResponse::ok($members);
    }

    /** Daftar staf yang dapat memberi otorisasi di outlet perangkat beserta aksi yang diizinkan (FR-AUTH-07). */
    public function supervisors(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $outlet = $device->outlet;

        $members = CompanyUser::query()
            ->with(['user.roles.permissions', 'user.permissions'])
            ->where('is_active', true)
            ->whereNotNull('pin_hash')
            ->get();
        $this->scope->prime($members);

        $list = $members
            ->filter(fn (CompanyUser $m) => $this->scope->allowsOutlet($m->user, $outlet))
            ->map(function (CompanyUser $m) {
                $actions = collect(PermissionRegistry::SUPERVISOR_ACTIONS)
                    ->filter(fn (string $permission) => $m->user->can($permission))
                    ->keys()
                    ->values();

                return [
                    'id' => $m->id,
                    'name' => $m->user->name,
                    'actions' => $actions,
                    'locked' => $m->isPinLocked(),
                ];
            })
            ->filter(fn (array $row) => $row['actions']->isNotEmpty())
            ->sortBy('name')
            ->values();

        return ApiResponse::ok($list);
    }

    public function login(PinLoginRequest $request): JsonResponse
    {
        $device = $this->device($request);

        $member = CompanyUser::query()->with('user')->find($request->string('staff_id')->toString());
        if ($member === null) {
            return ApiResponse::error(422, [['code' => 'STAFF_NOT_FOUND', 'field' => 'staff_id', 'message' => 'Staf tidak ditemukan di company ini.']]);
        }

        $this->pins->verifyLogin($member, $request->string('pin')->toString(), $device->outlet);

        /** @var User $user */
        $user = $member->user;
        $token = DB::transaction(function () use ($user, $device) {
            // Satu sesi kasir aktif per user per device.
            PersonalAccessToken::query()
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->id)
                ->where('device_id', $device->id)
                ->delete();

            $token = $user->createToken('pos:'.$device->code, ['pos'], now()->addHours(16));
            $token->accessToken->forceFill([
                'company_id' => $device->company_id,
                'device_id' => $device->id,
            ])->save();

            return $token;
        });

        $request->attributes->set('pos_user_id', $user->id);
        $this->audit->log('pos.login', $device, userId: $user->id);

        $role = $user->roles->first();

        return ApiResponse::ok([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'staff' => [
                'id' => $member->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'roles' => $user->getRoleNames()->values(),
                'permissions' => $user->getAllPermissions()->pluck('name')->filter(fn ($p) => str_starts_with($p, 'pos.') || str_starts_with($p, 'menu.'))->values(),
                'max_discount_percent' => $role instanceof Role ? $role->max_discount_percent : '0.00',
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $this->actor($request)->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return ApiResponse::ok(['logged_out' => true]);
    }

    public function authorizeAction(AuthorizeActionRequest $request): JsonResponse
    {
        $device = $this->device($request);
        $context = array_filter($request->only(['reference_type', 'reference_id', 'amount', 'discount_percent']), fn ($v) => $v !== null);
        $action = $request->string('action')->toString();

        $supervisor = $this->pins->authorizeAction(
            $action,
            $request->string('supervisor_id')->toString(),
            $request->string('pin')->toString(),
            $device,
            $request->input('reason'),
            $context,
        );

        if ($action === 'discount' && $request->filled('discount_percent')) {
            $limit = $supervisor->user->roles->max(fn ($r) => (float) ($r instanceof Role ? $r->max_discount_percent : 0));
            if ((float) $request->input('discount_percent') > (float) $limit) {
                return ApiResponse::error(403, [[
                    'code' => 'DISCOUNT_LIMIT_EXCEEDED',
                    'field' => 'discount_percent',
                    'message' => 'Diskon melebihi batas yang boleh disetujui pemilik PIN ini ('.rtrim(rtrim((string) $limit, '0'), '.').'%).',
                ]]);
            }
        }

        $authorizationId = $this->audit->log(
            'pos.authorization_granted',
            $device,
            reason: $request->input('reason'),
            authorizedBy: $supervisor->user_id,
            metadata: ['action' => $action] + $context,
        );

        return ApiResponse::ok([
            'authorization_id' => $authorizationId,
            'action' => $action,
            'authorized_by' => ['id' => $supervisor->user_id, 'name' => $supervisor->user->name],
            'valid_until' => now()->addMinutes(Authorizations::ONLINE_VALID_MINUTES)->toIso8601String(),
        ]);
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $device->loadMissing('outlet');

        return $device;
    }
}
