<?php

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Payment\Application\PaymentMethods;
use App\Modules\Sales\Domain\Models\GatewayRefundRequest;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Refund;
use App\Modules\Tenancy\Domain\Models\Device;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Pengembalian dana untuk transaksi berbayar gateway (keputusan user 30 Sep 2026).
 *
 * AINO tidak punya API refund, jadi uangnya hanya bisa dikembalikan manual dari dashboard acquirer.
 * Alurnya karena itu dipecah dua, dan pemisahan itulah inti kelas ini:
 *
 * 1. `request()` — kasir mengajukan. Tidak ada uang yang berpindah, tidak ada angka laporan yang
 *    berubah, stok belum bergerak. Yang dihasilkan cuma satu baris tugas dan satu tanda di pesanan.
 * 2. `settle()` — finance sudah benar-benar mengembalikan dananya di dashboard acquirer dan
 *    mencatatkan nomor referensinya di sini. Baru pada detik itu retur sungguhan lahir.
 *
 * Tidak ada satu pun jalan bagi manusia di sistem ini untuk menyatakan "uang sudah kembali" tanpa
 * membawa nomor referensi dari pihak yang benar-benar memindahkan uangnya — sama seperti sikap yang
 * sudah dipakai pada pemeriksaan ulang pembayaran: menyatakan dana berpindah adalah wewenang
 * acquirer, bukan aplikasi kasir.
 */
class GatewayRefundService
{
    public function __construct(
        private readonly ShiftService $shifts,
        private readonly Authorizations $auth,
        private readonly BusinessCalendar $calendar,
        private readonly RefundAmount $amounts,
        private readonly RefundWriter $writer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Kasir mengajukan pengembalian dana. Idempoten lewat `id` seperti entitas sinkron lainnya.
     *
     * @param  array<string, mixed>  $input
     */
    public function request(Device $device, string $orderId, array $input): GatewayRefundRequest
    {
        $validator = Validator::make($input, [
            'id' => ['required', 'uuid'],
            'shift_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'method' => ['required', 'string', Rule::in(PaymentMethods::GATEWAY_METHODS)],
            'stock_action' => ['required', Rule::in(['return', 'waste'])],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'requested_by' => ['required', 'uuid'],
            'created_at' => ['required', 'date'],
            'authorization' => ['nullable', 'array'],
            'lines' => ['nullable', 'array', 'max:200'],
            'lines.*.order_item_id' => ['required', 'uuid', 'distinct'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
        ]);
        if ($validator->fails()) {
            throw new SalesException('VALIDATION_FAILED', 'Data pengajuan pengembalian dana tidak valid.', 422, details: ['errors' => $validator->errors()->toArray()]);
        }
        $data = $validator->validated();
        $device->loadMissing('outlet');
        $outlet = $device->outlet;
        $at = $this->shifts->time($data['created_at'], 'created_at');

        return DB::transaction(function () use ($device, $outlet, $orderId, $data, $at): GatewayRefundRequest {
            /** @var Order|null $order */
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            if ($order === null) {
                throw new SalesException('ORDER_NOT_FOUND', 'Transaksi belum diterima server.', 409, true, 'order_id');
            }
            if ($order->outlet_id !== $outlet->id) {
                throw new SalesException('ORDER_NOT_IN_OUTLET', 'Transaksi milik outlet lain.', 422, field: 'order_id');
            }
            if (! in_array($order->status, [Order::PAID, Order::PARTIALLY_REFUNDED], true)) {
                throw new SalesException('ORDER_NOT_REFUNDABLE', 'Transaksi ini tidak dapat di-refund.', 409, field: 'order_id', details: ['status' => $order->status]);
            }
            // Hanya masuk akal untuk dana yang memang masuk lewat gateway itu.
            if (! in_array($data['method'], $order->payments()->pluck('method')->all(), true)) {
                throw new SalesException('REFUND_METHOD_INVALID', 'Transaksi ini tidak dibayar dengan metode tersebut.', 422, field: 'method');
            }

            $shift = $this->shifts->openShift($device, $data['shift_id']);
            $this->shifts->assertWithinShift($shift, $at);
            $this->shifts->assertSameBusinessDay($shift, $at);

            $actor = $this->auth->staff($data['requested_by'], $outlet, 'pos.transact', 'requested_by');
            $approver = null;
            if (! $this->auth->selfAuthorized($actor, 'pos.void', $outlet)) {
                // Pengajuan mengikat: begitu finance menyelesaikannya, uang benar-benar keluar.
                // Karena itu ambangnya disamakan dengan retur, bukan diturunkan.
                [$approver] = $this->auth->verify($data['authorization'] ?? null, 'refund', $device, $at, 'authorization', 'gateway_refund:'.$data['id'], [
                    'reference_id' => $order->id,
                    'amount' => (string) $data['amount'],
                ]);
            }

            $remaining = $this->amounts->remaining($order);
            [$expected, $lines] = $this->amounts->expected($order, $data['lines'] ?? [], $remaining);
            if (! BigDecimal::of((string) $data['amount'])->isEqualTo($expected)) {
                throw new SalesException('REFUND_AMOUNT_MISMATCH', 'Nominal pengembalian dana tidak sesuai perhitungan server.', 422, field: 'amount', details: ['expected' => (string) $expected]);
            }

            $request = new GatewayRefundRequest;
            $request->forceFill([
                'id' => $data['id'],
                'company_id' => $order->company_id,
                'outlet_id' => $outlet->id,
                'order_id' => $order->id,
                'order_business_date' => $order->business_date->format('Y-m-d'),
                'business_date' => $shift->business_date->format('Y-m-d'),
                'shift_id' => $shift->id,
                'amount' => (string) $expected,
                'method' => $data['method'],
                'lines' => $lines,
                'stock_action' => $data['stock_action'],
                'reason' => trim((string) $data['reason']),
                'requested_by' => $actor->id,
                'authorized_by' => $approver?->id,
                'status' => GatewayRefundRequest::PENDING,
                'device_created_at' => $at,
                'server_received_at' => now(),
            ])->save();

            $this->flag($order, add: true);

            $this->audit->log('order.gateway_refund_requested', $order,
                new: ['request_id' => $request->id, 'amount' => $request->amount, 'method' => $request->method],
                reason: $request->reason, authorizedBy: $approver?->id, userId: $actor->id);

            return $request;
        });
    }

    /**
     * Finance menyatakan dananya sudah dikembalikan lewat dashboard acquirer. Baru di sini retur lahir.
     */
    public function settle(GatewayRefundRequest $request, User $by, string $gatewayReference, ?string $note = null): Refund
    {
        $reference = trim($gatewayReference);
        if ($reference === '') {
            throw new SalesException('GATEWAY_REFERENCE_REQUIRED', 'Nomor referensi pengembalian dana dari gateway wajib diisi.', 422, field: 'gateway_reference');
        }

        return DB::transaction(function () use ($request, $by, $reference, $note): Refund {
            /** @var GatewayRefundRequest $locked */
            $locked = GatewayRefundRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);

            /** @var Order $order */
            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            $order->loadMissing('outlet');

            /*
             * Keadaan bisa berubah antara pengajuan dan penyelesaian — misalnya kasir sudah terlanjur
             * meretur tunai barang yang sama. Angka pengajuan karena itu dihitung ULANG, dan bila
             * hasilnya berbeda pengajuan ditolak dengan angka yang benar, bukan dipaksakan. Memaksakan
             * angka lama berarti mengembalikan uang untuk barang yang sudah dikembalikan uangnya.
             */
            $remaining = $this->amounts->remaining($order, $locked->id);
            [$expected] = $this->amounts->expected($order, $locked->lines, $remaining, $locked->id);
            if (! BigDecimal::of((string) $locked->amount)->isEqualTo($expected)) {
                throw new SalesException('REFUND_AMOUNT_MISMATCH',
                    'Nilai transaksi sudah berubah sejak pengajuan dibuat (mungkin sebagian sudah diretur). Batalkan pengajuan ini lalu buat pengajuan baru.',
                    409, field: 'amount', details: ['expected' => (string) $expected, 'requested' => (string) $locked->amount]);
            }

            $refundId = (string) Str::uuid7();
            $refund = $this->writer->write($order, [
                'id' => $refundId,
                'company_id' => $order->company_id,
                'outlet_id' => $order->outlet_id,
                'order_id' => $order->id,
                'order_business_date' => $order->business_date->format('Y-m-d'),
                // Dicatat pada hari bisnis saat dananya benar-benar dikembalikan (BR-13), bukan saat diajukan.
                'business_date' => $this->calendar->today($order->outlet)->format('Y-m-d'),
                'shift_id' => null,
                'amount' => (string) $expected,
                'method' => $locked->method,
                'lines' => $locked->lines,
                'stock_action' => $locked->stock_action,
                'reason' => $locked->reason,
                'refunded_by' => $by->id,
                'authorized_by' => $locked->authorized_by,
                'flags' => ['gateway_refund_settled'],
                'device_created_at' => $locked->device_created_at,
                'server_received_at' => now(),
            ], $by->id);

            $locked->forceFill([
                'status' => GatewayRefundRequest::SETTLED,
                'gateway_reference' => $reference,
                'refund_id' => $refund->id,
                'resolved_by' => $by->id,
                'resolution_note' => $this->note($note),
                'resolved_at' => now(),
            ])->save();

            $this->clearFlagIfDone($order);

            $this->audit->log('order.gateway_refund_settled', $order,
                new: ['request_id' => $locked->id, 'refund_id' => $refund->id, 'amount' => $refund->amount, 'gateway_reference' => $reference],
                reason: $locked->reason, userId: $by->id);

            return $refund;
        });
    }

    /** Pengajuan dibatalkan — dana tidak jadi dikembalikan. Tidak pernah melahirkan retur. */
    public function cancel(GatewayRefundRequest $request, User $by, string $reason): GatewayRefundRequest
    {
        $text = trim($reason);
        if (mb_strlen($text) < 3) {
            throw new SalesException('CANCEL_REASON_REQUIRED', 'Alasan pembatalan wajib diisi.', 422, field: 'resolution_note');
        }

        return DB::transaction(function () use ($request, $by, $text): GatewayRefundRequest {
            /** @var GatewayRefundRequest $locked */
            $locked = GatewayRefundRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);

            $locked->forceFill([
                'status' => GatewayRefundRequest::CANCELLED,
                'resolved_by' => $by->id,
                'resolution_note' => mb_substr($text, 0, 300),
                'resolved_at' => now(),
            ])->save();

            /** @var Order $order */
            $order = Order::query()->whereKey($locked->order_id)->firstOrFail();
            $this->clearFlagIfDone($order);

            $this->audit->log('order.gateway_refund_cancelled', $order,
                new: ['request_id' => $locked->id, 'amount' => $locked->amount],
                reason: $text, userId: $by->id);

            return $locked;
        });
    }

    private function assertPending(GatewayRefundRequest $request): void
    {
        if ($request->status !== GatewayRefundRequest::PENDING) {
            throw new SalesException('GATEWAY_REFUND_ALREADY_RESOLVED', 'Pengajuan ini sudah diselesaikan orang lain.', 409, field: 'status', details: ['status' => $request->status]);
        }
    }

    private function note(?string $note): ?string
    {
        $text = trim((string) $note);

        return $text === '' ? null : mb_substr($text, 0, 300);
    }

    /** Tanda hanya dibuang bila tidak ada lagi pengajuan yang menunggu di pesanan itu. */
    private function clearFlagIfDone(Order $order): void
    {
        $stillPending = GatewayRefundRequest::query()
            ->where('order_id', $order->id)
            ->where('status', GatewayRefundRequest::PENDING)
            ->exists();
        if (! $stillPending) {
            $this->flag($order, add: false);
        }
    }

    private function flag(Order $order, bool $add): void
    {
        $flags = $order->flags;
        $has = in_array(GatewayRefundRequest::ORDER_FLAG, $flags, true);
        if ($add === $has) {
            return;
        }
        $order->forceFill([
            'flags' => $add
                ? array_values(array_unique([...$flags, GatewayRefundRequest::ORDER_FLAG]))
                : array_values(array_filter($flags, fn (string $f) => $f !== GatewayRefundRequest::ORDER_FLAG)),
        ])->save();
    }
}
