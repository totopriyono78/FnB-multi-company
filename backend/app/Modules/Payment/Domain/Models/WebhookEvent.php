<?php

namespace App\Modules\Payment\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Webhook masuk dari mitra (SRS §4.4): dicatat apa adanya untuk audit & replay. Ditulis lewat mode sistem; RLS membatasi baca per company.
 *
 * @property string $id
 * @property string $provider
 * @property string $event_id
 * @property bool $signature_valid
 * @property string|null $company_id
 * @property array<string, mixed> $payload
 * @property string|null $result
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 */
class WebhookEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
