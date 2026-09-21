<?php

namespace App\Modules\Audit\Http\Resources;

use App\Modules\Audit\Domain\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'entity' => $this->auditable_type,
            'entity_id' => $this->auditable_id,
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null),
            'authorized_by' => $this->whenLoaded('authorizer', fn () => $this->authorizer ? ['id' => $this->authorizer->id, 'name' => $this->authorizer->name] : null),
            'device_id' => $this->device_id,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'reason' => $this->reason,
            'metadata' => $this->metadata,
            'ip_address' => $this->ip_address,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
