<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\RoleScope;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Application\PlanLimits;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Undangan bergabung ke company untuk akun yang sudah ada (FR-AUTH-04). */
class InvitationController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->actor($request);

        $rows = $this->context->runAsSystem(fn () => CompanyUser::query()
            ->where('user_id', $user->id)
            ->whereNotNull('invited_at')
            ->whereNull('accepted_at')
            ->get(['id', 'company_id', 'invited_at']));

        $companies = $this->context->runAsSystem(
            fn () => Company::query()->whereIn('id', $rows->pluck('company_id'))->pluck('name', 'id')
        );

        return ApiResponse::ok($rows->map(fn (CompanyUser $m) => [
            'id' => $m->id,
            'company_id' => $m->company_id,
            'company_name' => $companies[$m->company_id] ?? null,
            'invited_at' => $m->invited_at?->toIso8601String(),
        ])->values());
    }

    public function accept(Request $request, string $invitation): JsonResponse
    {
        $member = $this->pending($request, $invitation);

        $this->context->runAsTenant($member->company_id, function () use ($member): void {
            app(PlanLimits::class)->ensureCanAddUser();
            $member->forceFill(['accepted_at' => now(), 'is_active' => true])->save();
            $this->audit->log('user.invitation_accepted', $member);
        });

        return ApiResponse::ok(['id' => $member->id, 'company_id' => $member->company_id, 'accepted' => true]);
    }

    public function decline(Request $request, string $invitation): JsonResponse
    {
        $member = $this->pending($request, $invitation);

        $this->context->runAsTenant($member->company_id, function () use ($member): void {
            DB::transaction(function () use ($member): void {
                $this->audit->log('user.invitation_declined', $member);
                DB::table('model_has_roles')
                    ->where('company_id', $member->company_id)
                    ->where('model_uuid', $member->user_id)
                    ->delete();
                RoleScope::query()->where('company_user_id', $member->id)->delete();
                $member->delete();
            });
        });

        return ApiResponse::ok(['id' => $member->id, 'declined' => true]);
    }

    private function pending(Request $request, string $id): CompanyUser
    {
        $user = $this->actor($request);

        $member = $this->context->runAsSystem(fn () => CompanyUser::query()
            ->whereKey($id)
            ->where('user_id', $user->id)
            ->whereNotNull('invited_at')
            ->whereNull('accepted_at')
            ->first());

        abort_if($member === null, 404);

        return $member;
    }
}
