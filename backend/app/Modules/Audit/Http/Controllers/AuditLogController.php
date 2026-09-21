<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Audit\Http\Resources\AuditLogResource;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\RoleScope;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Shared\Support\Like;
use App\Modules\Tenancy\Domain\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Pencarian audit log (FR-AUD-02). */
class AuditLogController extends Controller
{
    public function __construct(private readonly AccessScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('audit.view');

        $filters = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'entity' => ['nullable', 'string', 'max:80'],
            'entity_id' => ['nullable', 'uuid'],
            'action' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [], ['from' => 'tanggal awal', 'to' => 'tanggal akhir']);

        $tz = (string) config('app.display_timezone');
        $query = AuditLog::query()
            ->with(['user', 'authorizer'])
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['entity'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($filters['entity_id'] ?? null, fn ($q, $v) => $q->where('auditable_id', $v))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', Like::startsWith($v)))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', CarbonImmutable::parse($v, $tz)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', CarbonImmutable::parse($v, $tz)->endOfDay()->utc()))
            ->orderByDesc('created_at');

        // Manajer outlet hanya melihat aktivitas di outlet yang dikelolanya (Lampiran 12.1).
        $mine = $this->scope->for($this->actor($request));
        if ($mine !== null) {
            $deviceIds = Device::query()->whereIn('outlet_id', $mine['outlets'])->pluck('id');
            $userIds = DB::table('company_users')
                ->join('role_scopes', 'role_scopes.company_user_id', '=', 'company_users.id')
                ->where('role_scopes.scope_type', RoleScope::OUTLET)
                ->whereIn('role_scopes.scope_id', $mine['outlets'])
                ->pluck('company_users.user_id');

            $query->where(function ($q) use ($deviceIds, $userIds): void {
                $q->whereIn('device_id', $deviceIds)->orWhereIn('user_id', $userIds);
            });
        }

        return ApiResponse::ok(AuditLogResource::collection($query->paginate($this->perPage($request))));
    }
}
