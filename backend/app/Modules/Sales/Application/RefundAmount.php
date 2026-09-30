<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Domain\Models\GatewayRefundRequest;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Nominal retur yang dihitung server (BR-12).
 *
 * Diangkat keluar dari `RefundService` 30 Sep 2026 karena sejak ada pengajuan retur gateway ada DUA
 * jalur yang menghitung nominal untuk pesanan yang sama. Kalau keduanya punya salinan rumus sendiri,
 * perbedaan sekecil pembulatan sen akan membuat pengajuan ditolak saat diselesaikan — dan ketahuannya
 * baru di depan pelanggan.
 *
 * Yang dianggap SUDAH DIKLAIM bukan cuma retur yang sudah terjadi, melainkan juga pengajuan gateway
 * yang masih menunggu. Tanpa itu satu porsi bisa diretur dua kali: sekali tunai sekarang, sekali lagi
 * ketika finance menyelesaikan pengajuan yang terlupakan. Uangnya keluar dua kali untuk satu barang.
 */
class RefundAmount
{
    /**
     * Sisa nilai yang masih boleh diretur: total − yang sudah diretur − yang sudah diajukan.
     *
     * @param  string|null  $ignoreRequestId  Pengajuan yang sedang diselesaikan; tidak menghalangi dirinya sendiri.
     */
    public function remaining(Order $order, ?string $ignoreRequestId = null): BigDecimal
    {
        $claimed = GatewayRefundRequest::query()
            ->where('order_id', $order->id)
            ->where('status', GatewayRefundRequest::PENDING)
            ->when($ignoreRequestId !== null, fn ($q) => $q->whereKeyNot($ignoreRequestId))
            ->sum('amount');

        return BigDecimal::of((string) $order->total)
            ->minus((string) $order->refunded_total)
            ->minus((string) ($claimed ?? '0'));
    }

    /**
     * @param  list<array{order_item_id: string, qty: string|int|float}>  $requested
     * @return array{0: BigDecimal, 1: list<array{order_item_id: string, qty: string, amount: string}>}
     */
    public function expected(Order $order, array $requested, BigDecimal $remaining, ?string $ignoreRequestId = null): array
    {
        if ($remaining->isLessThanOrEqualTo(0)) {
            throw new SalesException('ORDER_NOT_REFUNDABLE', 'Transaksi sudah di-refund penuh atau sisanya sedang menunggu pengembalian dana.', 409, field: 'order_id');
        }
        if ($requested === []) {
            return [$remaining->toScale(2), []];
        }

        $items = OrderItem::query()->where('order_id', $order->id)->where('business_date', $order->business_date->format('Y-m-d'))->get()->keyBy('id');
        $netTotal = $items->reduce(fn (BigDecimal $c, OrderItem $i) => $c->plus((string) $i->net), BigDecimal::zero());
        $previous = $this->claimedQty($order, $ignoreRequestId);

        $amount = BigDecimal::zero();
        $lines = [];
        foreach ($requested as $i => $r) {
            /** @var OrderItem|null $item */
            $item = $items->get($r['order_item_id']);
            if ($item === null || $item->status !== 'sold') {
                throw new SalesException('REFUND_LINE_INVALID', 'Baris refund tidak ditemukan pada transaksi.', 422, field: "lines.{$i}.order_item_id");
            }
            $qty = BigDecimal::of((string) $r['qty']);
            $already = $previous[$item->id] ?? BigDecimal::zero();
            if ($qty->plus($already)->isGreaterThan((string) $item->qty)) {
                throw new SalesException('REFUND_QTY_EXCEEDED', 'Jumlah refund melebihi jumlah yang dibeli atau yang sedang menunggu pengembalian dana.', 422, field: "lines.{$i}.qty");
            }
            // Porsi baris terhadap total bayar (termasuk pajak, service charge, pembulatan).
            $share = $netTotal->isZero()
                ? BigDecimal::zero()
                : BigDecimal::of((string) $item->net)->multipliedBy($qty)->multipliedBy((string) $order->total)
                    ->dividedBy(BigDecimal::of((string) $item->qty)->multipliedBy($netTotal), 2, RoundingMode::HALF_UP);
            $amount = $amount->plus($share);
            $lines[] = ['order_item_id' => $item->id, 'qty' => (string) $qty, 'amount' => (string) $share];
        }

        // Refund yang menghabiskan semua sisa barang = sisa tagihan (hindari selisih pembulatan sen).
        $requestedQty = [];
        foreach ($lines as $line) {
            $requestedQty[$line['order_item_id']] = BigDecimal::of($line['qty']);
        }
        $coversAll = true;
        foreach ($items as $item) {
            if ($item->status !== 'sold') {
                continue;
            }
            $total = ($previous[$item->id] ?? BigDecimal::zero())->plus($requestedQty[$item->id] ?? BigDecimal::zero());
            if (! $total->isEqualTo((string) $item->qty)) {
                $coversAll = false;
                break;
            }
        }
        if ($coversAll || $amount->isGreaterThan($remaining)) {
            $amount = $remaining;
        }
        if (! $amount->isPositive()) {
            throw new SalesException('REFUND_AMOUNT_ZERO', 'Nominal refund nol.', 422, field: 'lines');
        }

        return [$amount->toScale(2), $lines];
    }

    /**
     * Jumlah per baris yang sudah diretur ATAU sedang diajukan.
     *
     * @return array<string, BigDecimal>
     */
    private function claimedQty(Order $order, ?string $ignoreRequestId): array
    {
        $claimed = [];
        $add = function (mixed $lines) use (&$claimed): void {
            if (! is_array($lines)) {
                return;
            }
            foreach ($lines as $l) {
                $claimed[$l['order_item_id']] = BigDecimal::of($claimed[$l['order_item_id']] ?? '0')->plus($l['qty']);
            }
        };

        foreach ($order->refunds()->get() as $old) {
            $add($old->lines);
        }
        $pending = GatewayRefundRequest::query()
            ->where('order_id', $order->id)
            ->where('status', GatewayRefundRequest::PENDING)
            ->when($ignoreRequestId !== null, fn ($q) => $q->whereKeyNot($ignoreRequestId))
            ->get();
        foreach ($pending as $request) {
            $add($request->lines);
        }

        return $claimed;
    }
}
