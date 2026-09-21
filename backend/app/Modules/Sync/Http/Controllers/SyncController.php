<?php

namespace App\Modules\Sync\Http\Controllers;

use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Sync\Application\SyncPullService;
use App\Modules\Sync\Application\SyncPushService;
use App\Modules\Sync\Application\SyncVersions;
use App\Modules\Tenancy\Domain\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Sinkronisasi offline POS (FR-DEV-04, NFR-OFF, ADR 0004). */
class SyncController extends Controller
{
    public function push(Request $request, SyncPushService $service, SyncVersions $versions): JsonResponse
    {
        $data = $request->validate([
            'batch_id' => ['required', 'uuid'],
            'entities' => ['required', 'array', 'min:1', 'max:'.SyncPushService::MAX_ENTITIES],
            'entities.*.type' => ['required', Rule::in(SyncPushService::TYPES)],
            'entities.*.id' => ['required', 'uuid'],
            'entities.*.payload' => ['required', 'array'],
        ]);
        $device = $this->device($request);

        $offset = null;
        if ($request->hasHeader('X-Device-Time')) {
            try {
                $offset = (int) round(CarbonImmutable::parse((string) $request->header('X-Device-Time'))->diffInSeconds(now(), false)) * -1;
                $offset = max(min($offset, 2_000_000_000), -2_000_000_000);
            } catch (\Throwable) {
                $offset = null;
            }
        }

        // Ambil payload mentah per entitas (validated() membuang kunci di dalam payload).
        $entities = [];
        $lineCount = 0;
        foreach ($data['entities'] as $i => $entity) {
            $payload = (array) $request->input("entities.{$i}.payload");
            $lineCount += is_array($payload['lines'] ?? null) ? count($payload['lines']) : 0;
            $entities[] = ['type' => $entity['type'], 'id' => $entity['id'], 'payload' => $payload];
        }
        if ($lineCount > SyncPushService::MAX_LINES) {
            return ApiResponse::error(422, [['code' => 'BATCH_TOO_LARGE', 'field' => 'entities', 'message' => 'Batch terlalu besar. Kirim maksimal '.SyncPushService::MAX_LINES.' baris pesanan per batch.']]);
        }

        $result = $service->push($device, $data['batch_id'], $entities, $offset);

        return ApiResponse::ok($result + [
            'clock_offset_seconds' => $offset,
            'sync_version' => $versions->current($device->company_id),
        ], ['server_time' => now()->toIso8601String()]);
    }

    public function pull(Request $request, SyncPullService $service): JsonResponse
    {
        $data = $request->validate(['since' => ['nullable', 'integer', 'min:0']]);

        return ApiResponse::ok($service->pull($this->device($request), isset($data['since']) ? (int) $data['since'] : null));
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        return $device->loadMissing('outlet');
    }
}
