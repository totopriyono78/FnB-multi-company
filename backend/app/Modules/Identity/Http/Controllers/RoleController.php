<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Application\GrantGuard;
use App\Modules\Identity\Application\PermissionRegistry;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Http\Requests\RoleRequest;
use App\Modules\Identity\Http\Resources\RoleResource;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/** FR-AUTH-05 */
class RoleController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionRegistrar $registrar,
        private readonly GrantGuard $grants,
    ) {}

    public function permissions(): JsonResponse
    {
        Gate::authorize('role.manage');

        $groups = [];
        foreach (PermissionRegistry::PERMISSIONS as $group => $items) {
            foreach ($items as $name => $label) {
                $groups[$group][] = ['name' => $name, 'label' => $label];
            }
        }

        return ApiResponse::ok($groups);
    }

    public function index(): JsonResponse
    {
        Gate::authorize('role.manage');

        $roles = Role::query()->where('company_id', $this->companyId())->with('permissions')->orderBy('is_system', 'desc')->orderBy('name')->get();

        return ApiResponse::ok(RoleResource::collection($roles));
    }

    public function store(RoleRequest $request): JsonResponse
    {
        Gate::authorize('role.manage');
        $actor = $this->actor($request);
        $this->grants->ensureCanGrantPermissions($actor, $request->input('permissions', []));
        $this->ensureDiscountWithinLimit($actor, $request->input('max_discount_percent'));

        $role = DB::transaction(function () use ($request): Role {
            $role = new Role;
            $role->forceFill([
                'company_id' => $this->companyId(),
                'name' => $request->string('name')->toString(),
                'label' => $request->string('label')->toString(),
                'guard_name' => 'web',
                'is_system' => false,
                'max_discount_percent' => $request->input('max_discount_percent', '0'),
            ])->save();
            $role->syncPermissions($request->input('permissions', []));

            return $role;
        });

        $this->registrar->forgetCachedPermissions();
        $this->audit->log('role.created', $role, new: $request->validated());

        return ApiResponse::created(new RoleResource($role->load('permissions')));
    }

    public function show(Role $role): JsonResponse
    {
        Gate::authorize('role.manage');
        $this->ensureOwned($role);

        return ApiResponse::ok(new RoleResource($role->load('permissions')));
    }

    public function update(RoleRequest $request, Role $role): JsonResponse
    {
        Gate::authorize('role.manage');
        $this->ensureOwned($role);

        if ($role->name === 'owner') {
            return ApiResponse::error(409, [['code' => 'ROLE_LOCKED', 'message' => 'Role Pemilik tidak dapat diubah.']]);
        }

        $actor = $this->actor($request);
        if (! $this->grants->isOwner($actor)) {
            if ($role->is_system) {
                throw new AuthorizationException('Role bawaan hanya dapat diubah oleh pemilik.');
            }
            if ($actor->hasRole($role->name)) {
                throw new AuthorizationException('Anda tidak dapat mengubah role yang Anda pegang sendiri.');
            }
        }
        $this->grants->ensureCanGrantPermissions($actor, $request->input('permissions', []));
        $this->ensureDiscountWithinLimit($actor, $request->input('max_discount_percent'));

        $before = ['label' => $role->label, 'max_discount_percent' => $role->max_discount_percent, 'permissions' => $role->permissions->pluck('name')->all()];

        DB::transaction(function () use ($request, $role): void {
            $role->forceFill($request->safe()->only(['label', 'max_discount_percent']))->save();
            if ($request->has('permissions')) {
                $role->syncPermissions($request->input('permissions'));
            }
        });

        $this->registrar->forgetCachedPermissions();
        $this->audit->log('role.updated', $role, $before, $request->validated());

        return ApiResponse::ok(new RoleResource($role->load('permissions')));
    }

    private function ensureDiscountWithinLimit(User $actor, mixed $value): void
    {
        if ($value !== null && ! $this->grants->isOwner($actor) && (float) $value > $this->grants->maxDiscount($actor)) {
            throw new AuthorizationException('Batas diskon role tidak boleh melebihi batas diskon Anda.');
        }
    }

    public function destroy(Role $role): JsonResponse
    {
        Gate::authorize('role.manage');
        $this->ensureOwned($role);

        if ($role->is_system) {
            return ApiResponse::error(409, [['code' => 'ROLE_LOCKED', 'message' => 'Role bawaan tidak dapat dihapus.']]);
        }

        $inUse = DB::table('model_has_roles')->where('role_id', $role->id)->exists();
        if ($inUse) {
            return ApiResponse::error(409, [['code' => 'ROLE_IN_USE', 'message' => 'Role masih dipakai user. Pindahkan user ke role lain terlebih dahulu.']]);
        }

        $role->delete();
        $this->registrar->forgetCachedPermissions();
        $this->audit->log('role.deleted', $role);

        return ApiResponse::ok(['id' => $role->id, 'deleted' => true]);
    }

    private function ensureOwned(Role $role): void
    {
        abort_unless($role->company_id === $this->companyId(), 404);
    }
}
