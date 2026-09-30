<?php

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Sales\Domain\Events\OrderRefunded;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Refund;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat sebuah retur benar-benar terjadi.
 *
 * Ada dua jalur yang bisa melahirkan retur — kasir di POS, dan finance yang menyelesaikan pengajuan
 * retur gateway — tetapi akibatnya harus persis sama: baris `refunds` tertulis, `orders.refunded_total`
 * bertambah, status pesanan berpindah, audit log terisi, dan stok bergerak lewat `OrderRefunded`.
 * Menyalin lima langkah itu ke jalur kedua adalah cara termudah membuat salah satunya tertinggal;
 * yang paling mudah terlupa justru `OrderRefunded`, dan akibatnya sunyi: uang kembali, stok tidak.
 */
class RefundWriter
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $row  Baris `refunds` yang sudah lengkap.
     * @param  string|null  $userId  Pelaku yang tercatat di audit log (kasir atau petugas back-office).
     */
    public function write(Order $order, array $row, ?string $userId): Refund
    {
        $refund = new Refund;
        $refund->forceFill($row)->save();

        $refunded = BigDecimal::of((string) $order->refunded_total)->plus((string) $row['amount']);
        $order->forceFill([
            'refunded_total' => (string) $refunded->toScale(2),
            'status' => $refunded->isEqualTo(BigDecimal::of((string) $order->total)) ? Order::REFUNDED : Order::PARTIALLY_REFUNDED,
        ])->save();

        $this->audit->log(
            'order.refunded',
            $order,
            new: ['refund_id' => $refund->id, 'amount' => $refund->amount, 'method' => $refund->method, 'status' => $order->status],
            reason: $refund->reason,
            authorizedBy: $refund->authorized_by,
            userId: $userId,
        );

        DB::afterCommit(fn () => OrderRefunded::dispatch($order->company_id, $order->id, $refund->id, $refund->stock_action));

        return $refund;
    }
}
