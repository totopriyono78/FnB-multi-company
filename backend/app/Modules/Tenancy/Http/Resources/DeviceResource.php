<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Domain\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Device */
class DeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'outlet_id' => $this->outlet_id,
            'outlet' => $this->whenLoaded('outlet', fn () => [
                'id' => $this->outlet->id,
                'code' => $this->outlet->code,
                'name' => $this->outlet->name,
            ]),
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'is_online' => $this->isOnline(),
            'platform' => $this->platform,
            'app_version' => $this->app_version,
            'pending_sync_count' => $this->pending_sync_count,
            'paired_at' => $this->paired_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
