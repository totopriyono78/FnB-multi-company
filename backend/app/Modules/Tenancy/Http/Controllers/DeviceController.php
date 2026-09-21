<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Identity\Application\AccessScope;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Application\DevicePairingService;
use App\Modules\Tenancy\Application\PlanLimits;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use App\Modules\Tenancy\Http\Requests\DeviceRequest;
use App\Modules\Tenancy\Http\Resources\DeviceResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Manajemen perangkat dari back-office (FR-DEV-01, FR-DEV-02, FR-DEV-07). */
class DeviceController extends Controller
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly PlanLimits $limits,
        private readonly DevicePairingService $pairing,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        $user = $this->actor($request);
        $devices = Device::query()
            ->with('outlet')
            ->when($request->filled('outlet_id'), fn ($q) => $q->where('outlet_id', $request->string('outlet_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when(! $this->scope->isCompanyWide($user), fn ($q) => $q->whereHas(
                'outlet',
                fn (Builder $o) => $this->scope->applyToOutletQuery($o, $user, 'outlets.id', 'outlets.brand_id')
            ))
            ->orderBy('code')
            ->paginate($this->perPage($request));

        return ApiResponse::ok(DeviceResource::collection($devices));
    }

    public function store(DeviceRequest $request): JsonResponse
    {
        $outlet = Outlet::query()->findOrFail($request->string('outlet_id'));
        $this->authorize('create', [Device::class, $outlet]);
        $this->limits->ensureCanAddDevice();

        $device = Device::query()->create($request->validated());
        $pairing = $this->pairing->issueCode($device);

        return ApiResponse::created([
            'device' => (new DeviceResource($device->load('outlet')))->resolve($request),
            'pairing' => [
                'code' => $pairing['code'],
                'expires_at' => $pairing['expires_at']->toIso8601String(),
            ],
        ]);
    }

    public function show(Device $device): JsonResponse
    {
        $this->authorize('view', $device);

        return ApiResponse::ok(new DeviceResource($device->load('outlet')));
    }

    public function update(DeviceRequest $request, Device $device): JsonResponse
    {
        $this->authorize('update', $device);

        $device->update($request->validated());

        return ApiResponse::ok(new DeviceResource($device->load('outlet')));
    }

    public function pairingCode(Device $device): JsonResponse
    {
        $this->authorize('update', $device);

        $pairing = $this->pairing->issueCode($device);

        return ApiResponse::ok([
            'code' => $pairing['code'],
            'expires_at' => $pairing['expires_at']->toIso8601String(),
        ]);
    }

    public function revoke(Request $request, Device $device): JsonResponse
    {
        $this->authorize('update', $device);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:200']], [], ['reason' => 'alasan']);

        $this->pairing->revoke($device, $validated['reason']);

        return ApiResponse::ok(new DeviceResource($device->refresh()->load('outlet')));
    }
}
