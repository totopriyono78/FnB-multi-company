<?php

namespace App\Modules\Sync\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Sales\Application\KitchenTicketRecorder;
use App\Modules\Sales\Application\OrderRecorder;
use App\Modules\Sales\Application\OrderVoider;
use App\Modules\Sales\Application\RefundService;
use App\Modules\Sales\Application\SalesException;
use App\Modules\Sales\Application\ShiftService;
use App\Modules\Sync\Domain\Models\SyncBatch;
use App\Modules\Sync\Domain\Models\SyncReceipt;
use App\Modules\Tenancy\Domain\Models\Device;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Penerimaan antrian outbox POS (FR-DEV-04, NFR-OFF-01..05, ADR 0004).
 * Setiap entitas diproses dalam transaksi sendiri dan idempoten berdasarkan (tipe, ID).
 */
class SyncPushService
{
    public const MAX_ENTITIES = 100;

    /** Total baris pesanan per batch (membatasi beban validasi harga & promo). */
    public const MAX_LINES = 1000;

    public const MAX_REJECTION_AUDITS_PER_HOUR = 200;

    public const TYPES = ['shift.open', 'cash_movement', 'kitchen.send', 'order', 'order.void', 'order.refund', 'shift.close'];

    public function __construct(
        private readonly ShiftService $shifts,
        private readonly OrderRecorder $orders,
        private readonly OrderVoider $voider,
        private readonly RefundService $refunds,
        private readonly KitchenTicketRecorder $tickets,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{type: string, id: string, payload: array<string, mixed>}>  $entities
     * @return array<string, mixed>
     */
    public function push(Device $device, string $batchId, array $entities, ?int $clockOffset): array
    {
        DB::table('sync_batches')->insertOrIgnore([
            'id' => $batchId,
            'company_id' => $device->company_id,
            'device_id' => $device->id,
            'entity_count' => count($entities),
            'clock_offset_seconds' => $clockOffset,
            'received_at' => now(),
        ]);
        $batch = SyncBatch::query()->find($batchId);
        if ($batch === null || $batch->device_id !== $device->id) {
            throw new SalesException('BATCH_CONFLICT', 'ID batch sudah dipakai perangkat lain.', 409, field: 'batch_id');
        }

        $results = [];
        $counts = ['accepted' => 0, 'duplicate' => 0, 'rejected' => 0];
        foreach ($entities as $entity) {
            $result = $this->process($device, $entity, $batchId);
            $counts[$result['status']]++;
            $results[] = $result;
        }

        DB::table('sync_batches')->where('id', $batchId)->update([
            'accepted_count' => DB::raw('accepted_count + '.$counts['accepted']),
            'duplicate_count' => DB::raw('duplicate_count + '.$counts['duplicate']),
            'rejected_count' => DB::raw('rejected_count + '.$counts['rejected']),
        ]);
        Device::query()->whereKey($device->id)->update(['last_synced_at' => now(), 'last_seen_at' => now()]);

        return ['batch_id' => $batchId, 'results' => $results] + $counts;
    }

    /**
     * Proses satu entitas (juga dipakai endpoint online agar idempotensi sama).
     *
     * @param  array{type: string, id: string, payload: array<string, mixed>}  $entity
     * @param  list<string>  $serverFilled  field yang diisi server (mis. waktu bawaan endpoint online); tidak ikut
     *                                      dibandingkan agar kiriman ulang permintaan yang sama tetap dianggap duplikat
     * @return array<string, mixed>
     */
    public function process(Device $device, array $entity, ?string $batchId = null, array $serverFilled = []): array
    {
        $type = $entity['type'];
        $id = $entity['id'];
        $hashed = array_diff_key($entity['payload'], array_flip($serverFilled));
        $hash = hash('sha256', $type.'|'.json_encode($this->canonical($hashed)));
        $base = ['type' => $type, 'id' => $id];

        $receipt = SyncReceipt::query()->where('entity_type', $type)->where('entity_id', $id)->first();
        if ($receipt !== null) {
            if ($receipt->payload_hash === $hash && $receipt->device_id === $device->id) {
                return $base + ['status' => 'duplicate'] + $receipt->result;
            }

            return $base + ['status' => 'rejected', 'error' => ['code' => 'CONFLICT', 'message' => 'ID sudah dipakai dengan isi berbeda.', 'retryable' => false]];
        }

        try {
            $refs = DB::transaction(function () use ($device, $type, $id, $entity, $hash, $batchId): array {
                // Kunci per entitas agar kiriman ganda yang bersamaan tidak diproses dua kali.
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['sync:'.$type.':'.$id]);
                $existing = SyncReceipt::query()->where('entity_type', $type)->where('entity_id', $id)->first();
                if ($existing !== null) {
                    return ['__duplicate' => $existing];
                }

                $refs = $this->apply($device, $type, $id, $entity['payload']);

                $receipt = new SyncReceipt;
                $receipt->forceFill([
                    'id' => (string) Str::uuid7(),
                    'company_id' => $device->company_id,
                    'device_id' => $device->id,
                    'batch_id' => $batchId,
                    'entity_type' => $type,
                    'entity_id' => $id,
                    'payload_hash' => $hash,
                    'result' => $refs,
                ])->save();

                return $refs;
            });
        } catch (SalesException $e) {
            // Dicatat sekali per entitas & kode agar pengiriman ulang tidak membanjiri audit log.
            // Batas per perangkat mencegah banjir audit dengan ID entitas yang selalu baru.
            if (! $e->retryable
                && Cache::add("sync-rejected:{$device->id}:{$type}:{$id}:{$e->errorCode}", true, now()->addDay())
                && RateLimiter::attempt('sync-reject-audit:'.$device->id, self::MAX_REJECTION_AUDITS_PER_HOUR, fn () => true, 3600)) {
                $this->audit->log('sync.entity_rejected', $device, metadata: ['type' => $type, 'id' => $id, 'code' => $e->errorCode, 'field' => $e->field]);
            }

            return $base + ['status' => 'rejected', 'error' => array_filter([
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'field' => $e->field,
                'retryable' => $e->retryable,
                'details' => $e->details === [] ? null : $e->details,
            ], fn ($v) => $v !== null)];
        } catch (UniqueConstraintViolationException) {
            // ID/nomor struk sudah dipakai data lain (termasuk yang tidak terlihat oleh company ini).
            return $base + ['status' => 'rejected', 'error' => ['code' => 'CONFLICT', 'message' => 'ID atau nomor struk sudah dipakai data lain.', 'retryable' => false]];
        } catch (Throwable $e) {
            report($e);

            return $base + ['status' => 'rejected', 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Terjadi kesalahan di server. Kirim ulang nanti.', 'retryable' => true]];
        }

        if (isset($refs['__duplicate'])) {
            /** @var SyncReceipt $dup */
            $dup = $refs['__duplicate'];

            return $dup->payload_hash === $hash && $dup->device_id === $device->id
                ? $base + ['status' => 'duplicate'] + $dup->result
                : $base + ['status' => 'rejected', 'error' => ['code' => 'CONFLICT', 'message' => 'ID sudah dipakai dengan isi berbeda.', 'retryable' => false]];
        }

        return $base + ['status' => 'accepted'] + $refs;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function apply(Device $device, string $type, string $id, array $payload): array
    {
        return match ($type) {
            'shift.open' => ['shift_id' => $this->shifts->open($device, ['id' => $id] + $payload)->id],
            'cash_movement' => ['cash_movement_id' => $this->shifts->recordCash($device, ['id' => $id] + $payload)->id],
            'shift.close' => (function () use ($device, $payload): array {
                $shift = $this->shifts->close($device, $this->string($payload, 'shift_id'), $payload);

                return ['shift_id' => $shift->id, 'expected_cash' => $shift->expected_cash, 'cash_variance' => $shift->cash_variance];
            })(),
            'kitchen.send' => ['ticket_id' => $this->tickets->record($device, ['id' => $id] + $payload)->id],
            'order' => (function () use ($device, $id, $payload): array {
                $order = $this->orders->record($device, ['id' => $id] + $payload);

                return ['order_id' => $order->id, 'receipt_no' => $order->receipt_no, 'flags' => $order->flags];
            })(),
            'order.void' => ['order_id' => $this->voider->void($device, $this->string($payload, 'order_id'), $payload)->id],
            'order.refund' => (function () use ($device, $id, $payload): array {
                $refund = $this->refunds->refund($device, $this->string($payload, 'order_id'), ['id' => $id] + $payload);

                return ['refund_id' => $refund->id, 'order_id' => $refund->order_id, 'amount' => $refund->amount];
            })(),
            default => throw new SalesException('UNKNOWN_TYPE', 'Jenis entitas tidak dikenal.', 422, field: 'type'),
        };
    }

    /** @param  array<string, mixed>  $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw new SalesException('VALIDATION_FAILED', "{$key} wajib berupa UUID.", 422, field: $key);
        }

        return $value;
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => $this->canonical($v), $value);
    }
}
