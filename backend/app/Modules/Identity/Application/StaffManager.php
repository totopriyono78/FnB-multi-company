<?php

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Application\Notifications\CompanyInvitation;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\PersonalAccessToken;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\RoleScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\PlanLimits;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Mengelola anggota company, role, dan cakupan akses (FR-AUTH-04/05/06/09). */
class StaffManager
{
    /** Role yang boleh diberikan oleh manajer outlet (Lampiran 12.1: "Outlet saja"). */
    public const OUTLET_ASSIGNABLE_ROLES = ['cashier', 'kitchen'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AccessScope $scope,
        private readonly PinService $pins,
        private readonly PlanLimits $limits,
        private readonly AuditLogger $audit,
        private readonly GrantGuard $grants,
    ) {}

    /**
     * Akun baru langsung aktif. Akun yang sudah ada (milik orang lain) hanya diundang
     * dan baru aktif setelah pemiliknya menerima undangan.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): CompanyUser
    {
        $this->limits->ensureCanAddUser();
        $this->guardAssignment($actor, $data['roles'], $data['scopes'] ?? []);

        return DB::transaction(function () use ($data): CompanyUser {
            [$user, $isNew] = $this->context->runAsSystem(function () use ($data): array {
                $existing = User::query()->where('email', $data['email'])->first();
                if ($existing !== null) {
                    return [$existing, false];
                }

                if (! empty($data['phone']) && User::query()->where('phone', $data['phone'])->exists()) {
                    throw ValidationException::withMessages(['phone' => 'Nomor HP sudah dipakai akun lain.']);
                }

                return [User::query()->create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'password' => Str::password(24),
                ]), true];
            });

            if (CompanyUser::query()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'User ini sudah terdaftar atau sudah diundang ke company.']);
            }

            $member = new CompanyUser([
                'user_id' => $user->id,
                'employee_code' => $data['employee_code'] ?? null,
            ]);
            $member->forceFill($isNew
                ? ['is_active' => true, 'accepted_at' => now()]
                : ['is_active' => false, 'invited_at' => now()]);
            $member->save();

            $this->syncRolesAndScopes($member, $data['roles'], $data['scopes'] ?? []);

            if (! empty($data['pin'])) {
                $this->pins->setPin($member, $data['pin']);
            }

            if ($isNew) {
                // Staf baru membuat password sendiri lewat tautan reset.
                $this->context->runAsSystem(fn () => Password::sendResetLink(['email' => $user->email]));
            } else {
                $company = Company::query()->findOrFail($member->company_id);
                $user->notify(new CompanyInvitation($company->name, $member->id));
                $this->audit->log('user.invited', $member);
            }

            return $member->load(['user.roles', 'scopes']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, CompanyUser $member, array $data): CompanyUser
    {
        $this->guardTarget($actor, $member);

        if (isset($data['roles']) || isset($data['scopes'])) {
            $this->guardAssignment(
                $actor,
                $data['roles'] ?? $member->user->getRoleNames()->all(),
                $data['scopes'] ?? $this->currentScopes($member),
            );
        }

        if (($data['is_active'] ?? null) === false && $member->user_id === $actor->id) {
            throw ValidationException::withMessages(['is_active' => 'Anda tidak dapat menonaktifkan akun sendiri.']);
        }

        // Hanya *mengaktifkan* yang dilarang. Bila anggotanya memang sudah aktif, penyuntingan lain
        // (ganti PIN, role, kode pegawai) tidak boleh ikut ditolak — form back-office selalu
        // mengirimkan `is_active` apa adanya, sehingga pemeriksaan tanpa syarat ini dulu membuat
        // setiap penyimpanan gagal dengan pesan yang tidak nyambung.
        if (($data['is_active'] ?? null) === true && ! $member->is_active && $member->isPendingInvitation()) {
            throw ValidationException::withMessages(['is_active' => 'Undangan belum diterima oleh pemilik akun.']);
        }

        if (isset($data['name']) && $data['name'] !== $member->user->name && ! $this->ownsIdentity($member)) {
            throw ValidationException::withMessages(['name' => 'Nama akun ini dikelola pemiliknya sendiri dan tidak dapat diubah dari company Anda.']);
        }

        return DB::transaction(function () use ($member, $data): CompanyUser {
            if (isset($data['name']) && $data['name'] !== $member->user->name) {
                $this->context->runAsSystem(fn () => $member->user->update(['name' => $data['name']]));
            }

            $member->fill(array_intersect_key($data, array_flip(['employee_code', 'is_active'])))->save();

            if (isset($data['roles']) || isset($data['scopes'])) {
                $this->syncRolesAndScopes(
                    $member,
                    $data['roles'] ?? $member->user->getRoleNames()->all(),
                    $data['scopes'] ?? $this->currentScopes($member),
                );
            }

            if (array_key_exists('pin', $data)) {
                $this->pins->setPin($member, $data['pin']);
            }

            if (($data['is_active'] ?? true) === false) {
                $this->revokeSessions($member);
            }

            return $member->refresh()->load(['user.roles', 'scopes']);
        });
    }

    /**
     * Mengakhiri semua sesi user untuk company ini saja (FR-AUTH-09).
     * Token tanpa ikatan company tetap ada, tetapi ditolak untuk company ini karena
     * dibuat sebelum sessions_revoked_at.
     */
    public function revokeSessions(CompanyUser $member): int
    {
        $companyId = $this->context->requireCompanyId();
        $member->forceFill(['sessions_revoked_at' => now()])->saveQuietly();

        $count = $this->context->runAsSystem(fn (): int => PersonalAccessToken::query()
            ->where('tokenable_type', $member->user->getMorphClass())
            ->where('tokenable_id', $member->user_id)
            ->where('company_id', $companyId)
            ->delete());

        $this->audit->log('user.sessions_revoked', $member, metadata: ['bound_tokens_deleted' => $count]);

        return $count;
    }

    public function guardTarget(User $actor, CompanyUser $member): void
    {
        $targetRoles = $member->user->getRoleNames()->all();

        if (in_array('owner', $targetRoles, true) && ! $this->grants->isOwner($actor)) {
            throw new AuthorizationException('Hanya pemilik yang dapat mengubah akun pemilik.');
        }

        if ($actor->can('user.manage')) {
            return;
        }

        if (array_diff($targetRoles, self::OUTLET_ASSIGNABLE_ROLES) !== []) {
            throw new AuthorizationException('Anda hanya dapat mengelola kasir dan staf dapur.');
        }

        $this->guardScopes($actor, $this->currentScopes($member));
    }

    /** Company hanya boleh mengubah identitas global akun yang dibuatnya sendiri dan tidak dipakai di company lain. */
    private function ownsIdentity(CompanyUser $member): bool
    {
        if ($member->invited_at !== null) {
            return false;
        }

        $memberships = $this->context->runAsSystem(
            fn (): int => DB::table('company_users')->where('user_id', $member->user_id)->count()
        );

        return $memberships === 1;
    }

    /**
     * @param  list<string>  $roles
     * @param  array{brands?: list<string>, outlets?: list<string>}  $scopes
     */
    private function guardAssignment(User $actor, array $roles, array $scopes): void
    {
        if (in_array('owner', $roles, true) && ! $this->grants->isOwner($actor)) {
            throw new AuthorizationException('Hanya pemilik yang dapat menambah pemilik lain.');
        }

        if (! $actor->can('user.manage')) {
            if (array_diff($roles, self::OUTLET_ASSIGNABLE_ROLES) !== []) {
                throw new AuthorizationException('Anda hanya dapat memberi role Kasir atau Dapur.');
            }
            $this->guardScopes($actor, $scopes);
        }

        $models = Role::query()->where('company_id', $this->context->requireCompanyId())->whereIn('name', $roles)->get();
        $this->grants->ensureCanAssignRoles($actor, $models);
    }

    /**
     * @param  array{brands?: list<string>, outlets?: list<string>}  $scopes
     */
    private function guardScopes(User $actor, array $scopes): void
    {
        $mine = $this->scope->for($actor);
        if ($mine === null) {
            return;
        }

        $outlets = $scopes['outlets'] ?? [];
        if (($scopes['brands'] ?? []) !== [] || $outlets === [] || array_diff($outlets, $mine['outlets']) !== []) {
            throw new AuthorizationException('Staf hanya boleh ditempatkan di outlet yang Anda kelola.');
        }
    }

    /**
     * @return array{brands: list<string>, outlets: list<string>}
     */
    private function currentScopes(CompanyUser $member): array
    {
        $rows = RoleScope::query()->where('company_user_id', $member->id)->get(['scope_type', 'scope_id']);

        return [
            'brands' => $rows->where('scope_type', RoleScope::BRAND)->pluck('scope_id')->values()->all(),
            'outlets' => $rows->where('scope_type', RoleScope::OUTLET)->pluck('scope_id')->values()->all(),
        ];
    }

    /**
     * @param  list<string>  $roles
     * @param  array{brands?: list<string>, outlets?: list<string>}  $scopes
     */
    private function syncRolesAndScopes(CompanyUser $member, array $roles, array $scopes): void
    {
        $models = Role::query()->whereIn('name', $roles)->where('company_id', $member->company_id)->get();
        $user = $member->user;
        $user->unsetRelation('roles');
        $user->syncRoles($models);

        $before = $this->currentScopes($member);
        RoleScope::query()->where('company_user_id', $member->id)->delete();
        foreach (['brands' => RoleScope::BRAND, 'outlets' => RoleScope::OUTLET] as $key => $type) {
            foreach (array_unique($scopes[$key] ?? []) as $id) {
                RoleScope::query()->create(['company_user_id' => $member->id, 'scope_type' => $type, 'scope_id' => $id]);
            }
        }

        $this->scope->flush();
        $this->audit->log('user.access_changed', $member, $before, ['roles' => $roles, 'scopes' => $scopes]);
    }
}
