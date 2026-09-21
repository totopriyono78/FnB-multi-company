<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Application\PinService;
use App\Modules\Identity\Application\StaffManager;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\RoleScope;
use App\Modules\Identity\Http\Requests\StaffRequest;
use App\Modules\Identity\Http\Resources\StaffResource;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Shared\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Kelola user & role per company (FR-AUTH-05, FR-AUTH-06, FR-AUTH-09). */
class StaffController extends Controller
{
    public function __construct(
        private readonly StaffManager $staff,
        private readonly AccessScope $scope,
        private readonly PinService $pins,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CompanyUser::class);
        $actor = $this->actor($request);
        $mine = $this->scope->for($actor);

        $members = CompanyUser::query()
            ->with(['user.roles', 'scopes'])
            ->when($mine !== null, fn ($q) => $q->whereHas('scopes', fn ($s) => $s
                ->where('scope_type', RoleScope::OUTLET)
                ->whereIn('scope_id', $mine['outlets'])))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = Like::contains((string) $request->string('search'));
                $q->whereHas('user', fn ($u) => $u->where('name', 'ilike', $term)->orWhere('email', 'ilike', $term));
            })
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('created_at')
            ->paginate($this->perPage($request));

        return ApiResponse::ok(StaffResource::collection($members));
    }

    public function store(StaffRequest $request): JsonResponse
    {
        $this->authorize('create', CompanyUser::class);

        $member = $this->staff->create($this->actor($request), $request->validated());

        return ApiResponse::created(new StaffResource($member));
    }

    public function show(CompanyUser $member): JsonResponse
    {
        $this->authorize('view', $member);

        return ApiResponse::ok(new StaffResource($member->load(['user.roles', 'scopes'])));
    }

    public function update(StaffRequest $request, CompanyUser $member): JsonResponse
    {
        $this->authorize('update', $member);

        $member = $this->staff->update($this->actor($request), $member, $request->validated());

        return ApiResponse::ok(new StaffResource($member));
    }

    public function setPin(Request $request, CompanyUser $member): JsonResponse
    {
        $this->authorize('update', $member);
        $this->staff->guardTarget($this->actor($request), $member);
        $data = $request->validate(['pin' => ['required', 'string', 'confirmed']]);

        $this->pins->setPin($member, $data['pin']);

        return ApiResponse::ok(['id' => $member->id, 'has_pin' => true]);
    }

    public function unlockPin(Request $request, CompanyUser $member): JsonResponse
    {
        $this->authorize('update', $member);
        $this->staff->guardTarget($this->actor($request), $member);

        $member->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null])->save();

        return ApiResponse::ok(['id' => $member->id, 'pin_locked' => false]);
    }

    public function revokeSessions(Request $request, CompanyUser $member): JsonResponse
    {
        $this->authorize('update', $member);
        $this->staff->guardTarget($this->actor($request), $member);

        $count = $this->staff->revokeSessions($member);

        return ApiResponse::ok(['id' => $member->id, 'revoked_tokens' => $count]);
    }
}
