<?php

namespace App\Modules\Audit\Application;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Domain\Models\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Menulis audit log (FR-POS-33, FR-AUD-01). */
class AuditLogger
{
    /** Atribut yang tidak boleh masuk audit log (NFR: tidak ada data sensitif di log). */
    private const REDACTED = ['password', 'pin_hash', 'remember_token', 'code_hash', 'token'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?string $reason = null,
        ?string $authorizedBy = null,
        array $metadata = [],
        ?string $companyId = null,
        ?string $userId = null,
        bool $forcePlatform = false,
    ): string {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $actor = $request?->user();

        $companyId = $forcePlatform ? null : $companyId;
        $companyId ??= $forcePlatform ? null : $this->context->companyId()
            ?? ($subject instanceof Company ? $subject->getKey() : ($subject?->getAttributes()['company_id'] ?? null));

        $deviceId = $actor instanceof Device ? $actor->id : $request?->attributes->get('device_id');
        $userId ??= $actor instanceof User ? $actor->id : $request?->attributes->get('pos_user_id');

        $id = (string) Str::uuid7();
        $row = [
            'id' => $id,
            'company_id' => $companyId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'authorized_by' => $authorizedBy,
            'action' => $action,
            'auditable_type' => $subject ? class_basename($subject) : null,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old === null ? null : json_encode($this->redact($old)),
            'new_values' => $new === null ? null : json_encode($this->redact($new)),
            'reason' => $reason,
            'metadata' => $metadata === [] ? null : json_encode($metadata),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'request_id' => $request?->attributes->get('request_id'),
            'created_at' => now(),
        ];

        $insert = fn () => DB::table('audit_logs')->insert($row);

        // Log platform (tanpa company) atau untuk company lain ditulis oleh proses sistem.
        if (! $this->context->hasTenant() || $companyId !== $this->context->companyId()) {
            $this->context->runAsSystem($insert);
        } else {
            $insert();
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array($key, self::REDACTED, true)) {
                $values[$key] = '[disembunyikan]';
            }
        }

        return $values;
    }
}
