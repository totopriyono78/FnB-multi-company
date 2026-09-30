<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Sales\Application\BusinessCalendar;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Application\DevicePairingService;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Http\Requests\HeartbeatRequest;
use App\Modules\Tenancy\Http\Requests\PairDeviceRequest;
use App\Modules\Tenancy\Http\Resources\DeviceResource;
use App\Modules\Tenancy\Http\Resources\OutletResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Endpoint yang dipanggil aplikasi POS/KDS sendiri. */
class DeviceSessionController extends Controller
{
    public function __construct(
        private readonly DevicePairingService $pairing,
        private readonly BusinessCalendar $calendar,
    ) {}

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

        /*
         * Hari bisnis outlet ikut dikirim tiap denyut (cacat dilaporkan user 30 Sep 2026).
         *
         * Shift menetapkan hari bisnisnya sekali saat dibuka, dan seluruh transaksi mewarisinya.
         * Shift yang lupa ditutup semalam karena itu menyerap penjualan pagi berikutnya ke tanggal
         * kemarin. Server sudah menolaknya, tetapi penolakan baru terasa setelah kasir mengetik
         * satu pesanan penuh — tanggal ini membuat layar kasir bisa memberi tahu lebih dulu.
         */
        $device->loadMissing('outlet');

        return ApiResponse::ok([
            'status' => $device->status->value,
            'wipe' => $device->wipe_requested_at !== null,
            'business_date' => $this->calendar->businessDate($device->outlet, CarbonImmutable::now())->format('Y-m-d'),
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
