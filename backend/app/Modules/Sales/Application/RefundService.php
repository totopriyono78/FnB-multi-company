<?php

namespace App\Modules\Sales\Application;

use App\Modules\Payment\Application\PaymentMethods;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Refund;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Tenancy\Domain\Models\Device;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Refund penuh/sebagian (FR-POS-17, BR-12, BR-13). Dicatat pada hari bisnis & shift saat refund dilakukan.
 * Nominal dihitung server secara proporsional terhadap total (termasuk pajak & service charge).
 */
class RefundService
{
    public function __construct(
        private readonly ShiftService $shifts,
        private readonly Authorizations $auth,
        private readonly BusinessCalendar $calendar,
        private readonly RefundAmount $amounts,
        private readonly RefundWriter $writer,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function refund(Device $device, string $orderId, array $input): Refund
    {
        $validator = Validator::make($input, [
            'id' => ['required', 'uuid'],
            'shift_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'method' => ['required', 'string', Rule::in(PaymentMethods::codes())],
            'stock_action' => ['required', Rule::in(['return', 'waste'])],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'refunded_by' => ['required', 'uuid'],
            'created_at' => ['required', 'date'],
            'authorization' => ['nullable', 'array'],
            'lines' => ['nullable', 'array', 'max:200'],
            'lines.*.order_item_id' => ['required', 'uuid', 'distinct'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
        ]);
        if ($validator->fails()) {
            throw new SalesException('VALIDATION_FAILED', 'Data refund tidak valid.', 422, details: ['errors' => $validator->errors()->toArray()]);
        }
        $data = $validator->validated();
        $device->loadMissing('outlet');
        $outlet = $device->outlet;
        $at = $this->shifts->time($data['created_at'], 'created_at');

        return DB::transaction(function () use ($device, $outlet, $orderId, $data, $at): Refund {
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

            /*
             * Retur lewat gateway TIDAK boleh dicatat dari kasir (keputusan user 30 Sep 2026).
             *
             * Sampai 30 Sep 2026 baris ini hanya menempelkan tanda `gateway_refund_required` lalu
             * meneruskan: retur tercatat lunas, padahal tidak ada perintah pengembalian yang pernah
             * dikirim ke acquirer — AINO belum menyediakan API refund sama sekali. Selisihnya tidak
             * muncul di mana pun; hanya pelanggan yang tahu uangnya tidak kembali.
             *
             * Penolakan ini di server, bukan cuma di layar: layar bisa diakali, token perangkat bisa
             * dipakai langsung ke API. Jalan keluarnya ada dua dan keduanya jujur — retur tunai
             * (butuh manajer, lihat penjaga metode di bawah), atau pengajuan lewat GatewayRefundService yang baru
             * jadi retur setelah finance benar-benar mengembalikan dananya.
             */
            if (in_array($data['method'], PaymentMethods::GATEWAY_METHODS, true)) {
                throw new SalesException(
                    'REFUND_GATEWAY_UNSUPPORTED',
                    'Dana '.PaymentMethods::DEFAULTS[$data['method']]['label'].' tidak bisa dikembalikan dari kasir. Pilih retur tunai dengan persetujuan manajer, atau ajukan pengembalian dana untuk diproses kantor.',
                    422,
                    field: 'method',
                    details: ['method' => $data['method']],
                );
            }

            $shift = $this->shifts->openShift($device, $data['shift_id']);
            $this->shifts->assertWithinShift($shift, $at);
            // Retur mengurangi kas shift; shift yang hari bisnisnya sudah lewat harus ditutup dulu.
            $this->shifts->assertSameBusinessDay($shift, $at);

            $actor = $this->auth->staff($data['refunded_by'], $outlet, 'pos.transact', 'refunded_by');
            $flags = [];
            $approver = null;
            if (! $this->auth->selfAuthorized($actor, 'pos.void', $outlet)) {
                [$approver, $offline] = $this->auth->verify($data['authorization'] ?? null, 'refund', $device, $at, 'authorization', 'refund:'.$data['id'], [
                    'reference_id' => $order->id,
                    'amount' => (string) $data['amount'],
                ]);
                if ($offline) {
                    $flags[] = 'offline_authorization';
                }
            }
            // Manajer yang login sendiri, atau supervisor pemberi otorisasi.
            $decider = $approver ?? $actor;

            // Hari transaksi sudah ditutup → hanya manajer ke atas (BR-13).
            if ($this->calendar->isClosed($outlet, $order->business_date)) {
                $ok = $approver !== null
                    ? $this->auth->userCan($approver, 'pos.end_of_day', $outlet)
                    : $this->auth->selfAuthorized($actor, 'pos.end_of_day', $outlet);
                if (! $ok) {
                    throw new SalesException('REFUND_REQUIRES_MANAGER', 'Refund untuk hari yang sudah ditutup memerlukan persetujuan manajer.', 403, field: 'authorization');
                }
                $flags[] = 'closed_day_refund';
            }

            $remaining = $this->amounts->remaining($order);
            [$expected, $lines] = $this->amounts->expected($order, $data['lines'] ?? [], $remaining);
            if (! BigDecimal::of((string) $data['amount'])->isEqualTo($expected)) {
                throw new SalesException('REFUND_AMOUNT_MISMATCH', 'Nominal refund tidak sesuai perhitungan server.', 422, field: 'amount', details: ['expected' => (string) $expected]);
            }

            // Refund lewat metode asal. Tunai untuk transaksi non-tunai hanya dengan persetujuan manajer
            // (mengurangi kas seharusnya di laci).
            $paidMethods = $order->payments()->pluck('method')->all();
            if (! in_array($data['method'], $paidMethods, true)) {
                $managerOk = $data['method'] === 'cash' && (
                    $approver !== null ? $this->auth->userCan($decider, 'pos.end_of_day', $outlet) : $this->auth->selfAuthorized($actor, 'pos.end_of_day', $outlet)
                );
                if (! $managerOk) {
                    throw new SalesException('REFUND_METHOD_INVALID', 'Refund harus melalui metode pembayaran asal; refund tunai untuk transaksi non-tunai memerlukan manajer.', 422, field: 'method');
                }
                $flags[] = 'refund_method_changed';
            }
            $refund = $this->writer->write($order, [
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
                'refunded_by' => $actor->id,
                'authorized_by' => $approver?->id,
                'flags' => array_values(array_unique($flags)),
                'device_created_at' => $at,
                'server_received_at' => now(),
            ], $actor->id);

            return $refund;
        });
    }
}
