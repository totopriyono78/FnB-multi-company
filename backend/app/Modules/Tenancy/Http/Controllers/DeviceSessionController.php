<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Application\DevicePairingService;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Http\Requests\HeartbeatRequest;
use App\Modules\Tenancy\Http\Requests\PairDeviceRequest;
use App\Modules\Tenancy\Http\Resources\DeviceResource;
use App\Modules\Tenancy\Http\Resources\OutletResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Endpoint yang dipanggil aplikasi POS/KDS sendiri. */
class DeviceSessionController extends Controller
{
    public function __construct(private readonly DevicePairingService $pairing) {}

    public function pair(PairDeviceRequest $request): JsonResponse
    {
        $result = $this->pairing->pair($request->string('code')->toString(), $request->only(['platform', 'app_version']));
        $device = $result['device'];

        return ApiResponse::ok([
            'token' => $result['token']->plainTextToken,
            'company_id' => $device->company_id,
            'device' => (new DeviceResource($device))->resolve($request),
            'outlet' => (new OutletResource($device->outlet))->resolve($request),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function heartbeat(HeartbeatRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $device->forceFill([
            'last_seen_at' => now(),
            'pending_sync_count' => $request->integer('pending_sync_count'),
            'app_version' => $request->input('app_version', $device->app_version),
            'last_synced_at' => $request->filled('last_synced_at') ? $request->date('last_synced_at') : $device->last_synced_at,
        ])->saveQuietly();

        return ApiResponse::ok([
            'status' => $device->status->value,
            'wipe' => $device->wipe_requested_at !== null,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        return ApiResponse::ok(new DeviceResource($device->load('outlet')));
    }
}
