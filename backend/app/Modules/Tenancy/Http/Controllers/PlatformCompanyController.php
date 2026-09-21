<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Shared\Support\Like;
use App\Modules\Tenancy\Application\CompanyRegistrar;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\CompanyStatus;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Http\Resources\CompanyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Manajemen tenant oleh Super Admin (FR-TEN-02). */
class PlatformCompanyController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('platform-admin');

        $companies = $this->context->runAsSystem(fn () => Company::query()
            ->with('plan')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', Like::contains((string) $request->string('search'))))
            ->orderBy('name')
            ->paginate($this->perPage($request)));

        return ApiResponse::ok(CompanyResource::collection($companies));
    }

    public function store(Request $request, CompanyRegistrar $registrar): JsonResponse
    {
        Gate::authorize('platform-admin');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'plan_code' => ['nullable', Rule::exists('plans', 'code')],
            'owner_email' => ['required', 'email:rfc'],
        ], [], ['name' => 'nama company', 'owner_email' => 'email pemilik']);

        $owner = $this->context->runAsSystem(fn () => User::query()->where('email', mb_strtolower($data['owner_email']))->first());
        if ($owner === null) {
            return ApiResponse::error(422, [[
                'code' => 'OWNER_NOT_FOUND',
                'field' => 'owner_email',
                'message' => 'Pemilik belum punya akun. Minta pemilik mendaftar terlebih dahulu.',
            ]]);
        }

        $company = $registrar->register(['name' => $data['name'], 'city' => $data['city'] ?? null], $owner, $data['plan_code'] ?? null);

        return ApiResponse::created(new CompanyResource($this->find($company->id)));
    }

    public function suspend(Request $request, string $company): JsonResponse
    {
        Gate::authorize('platform-admin');
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']], [], ['reason' => 'alasan']);

        $model = $this->find($company);
        $this->context->runAsSystem(function () use ($model, $data): void {
            $model->forceFill([
                'status' => CompanyStatus::Suspended,
                'suspended_at' => now(),
                'suspension_reason' => $data['reason'],
            ])->save();
        });
        $this->audit->log('platform.company_suspended', $model, reason: $data['reason'], companyId: $model->id);

        return ApiResponse::ok(new CompanyResource($model));
    }

    public function activate(string $company): JsonResponse
    {
        Gate::authorize('platform-admin');

        $model = $this->find($company);
        $this->context->runAsSystem(function () use ($model): void {
            $model->forceFill([
                'status' => CompanyStatus::Active,
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();
        });
        $this->audit->log('platform.company_activated', $model, companyId: $model->id);

        return ApiResponse::ok(new CompanyResource($model));
    }

    public function destroy(Request $request, string $company): JsonResponse
    {
        Gate::authorize('platform-admin');
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']], [], ['reason' => 'alasan']);

        $model = $this->find($company);
        $this->context->runAsSystem(fn () => $model->delete());
        $this->audit->log('platform.company_deleted', $model, reason: $data['reason'], companyId: $model->id);

        return ApiResponse::ok(['id' => $model->id, 'deleted' => true]);
    }

    private function find(string $id): Company
    {
        abort_unless(Str::isUuid($id), 404);

        return $this->context->runAsSystem(fn () => Company::query()->with('plan')->findOrFail($id));
    }
}
