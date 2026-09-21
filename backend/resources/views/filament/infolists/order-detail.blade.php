@php
    /** @var \App\Modules\Sales\Domain\Models\Order $order */
    $order = $getRecord();
    $rp = fn ($v) => \App\Filament\Support\MenuFields::rupiah((string) $v);
    $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
    $tz = (string) config('app.display_timezone');
@endphp

<div class="fnb-stack">
    <x-filament::section heading="Rincian pesanan">
        <table class="fnb-receipt" aria-label="Rincian pesanan">
            <thead>
                <tr>
                    <th scope="col">Menu</th>
                    <th scope="col" class="fnb-num">Jumlah</th>
                    <th scope="col" class="fnb-num">Harga</th>
                    <th scope="col" class="fnb-num">Diskon</th>
                    <th scope="col" class="fnb-num">Bersih</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>
                            <span class="fnb-receipt__name">{{ $item->name }}{{ $item->variant_name ? ' – '.$item->variant_name : '' }}</span>
                            @foreach ($item->modifiers as $mod)
                                <span class="fnb-receipt__sub">{{ $mod['name'] ?? '' }}@if ((float) ($mod['price'] ?? 0) > 0) (+{{ $rp($mod['price']) }})@endif</span>
                            @endforeach
                            @foreach ($item->bundle as $choice)
                                <span class="fnb-receipt__sub">{{ $choice['name'] ?? '' }}</span>
                            @endforeach
                            @if ($item->catalog_price !== null && (float) $item->catalog_price !== (float) $item->unit_price)
                                <span class="fnb-receipt__sub">Harga katalog {{ $rp($item->catalog_price) }}</span>
                            @endif
                            @if ($item->status === 'voided')
                                <span class="fnb-receipt__sub">Dibatalkan</span>
                            @endif
                            @if ($item->note)
                                <span class="fnb-receipt__sub">Catatan: {{ $item->note }}</span>
                            @endif
                        </td>
                        <td class="fnb-num">{{ $qty($item->qty) }}</td>
                        <td class="fnb-num">{{ $rp($item->unit_price) }}</td>
                        <td class="fnb-num">{{ (float) $item->item_discount + (float) $item->order_discount > 0 ? '-'.$rp((string) \Brick\Math\BigDecimal::of((string) $item->item_discount)->plus((string) $item->order_discount)) : '-' }}</td>
                        <td class="fnb-num">{{ $rp($item->net) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <dl class="fnb-totals">
            <div><dt>Subtotal</dt><dd>{{ $rp($order->subtotal) }}</dd></div>
            @if ((float) $order->item_discount > 0)
                <div><dt>Diskon menu</dt><dd>-{{ $rp($order->item_discount) }}</dd></div>
            @endif
            @if ((float) $order->order_discount > 0)
                <div><dt>Diskon transaksi</dt><dd>-{{ $rp($order->order_discount) }}</dd></div>
            @endif
            @if ((float) $order->service_charge > 0)
                <div><dt>Service charge</dt><dd>{{ $rp($order->service_charge) }}</dd></div>
            @endif
            <div><dt>{{ $order->tax_name }}{{ ($order->pricing['tax_inclusive'] ?? false) ? ' (sudah termasuk)' : '' }}</dt><dd>{{ $rp($order->tax) }}</dd></div>
            @if ((float) $order->rounding != 0)
                <div><dt>Pembulatan</dt><dd>{{ $rp($order->rounding) }}</dd></div>
            @endif
            <div class="fnb-totals__grand"><dt>Total</dt><dd>{{ $rp($order->total) }}</dd></div>
            @if ((float) $order->refunded_total > 0)
                <div><dt>Sudah direfund</dt><dd>-{{ $rp($order->refunded_total) }}</dd></div>
            @endif
        </dl>
    </x-filament::section>

    <x-filament::section heading="Pembayaran">
        @if ($order->payments->isEmpty())
            <p class="fnb-muted">Tidak ada pembayaran.</p>
        @else
            <table class="fnb-receipt" aria-label="Pembayaran">
                <thead>
                    <tr>
                        <th scope="col">Metode</th>
                        <th scope="col">Referensi</th>
                        <th scope="col" class="fnb-num">Nominal</th>
                        <th scope="col" class="fnb-num">Diterima</th>
                        <th scope="col" class="fnb-num">Kembalian</th>
                        <th scope="col" class="fnb-num">MDR</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->payments as $payment)
                        <tr>
                            <td>{{ \App\Filament\Support\SalesLabels::method($payment->method) }}</td>
                            <td>{{ $payment->reference ?? '-' }}</td>
                            <td class="fnb-num">{{ $rp($payment->amount) }}</td>
                            <td class="fnb-num">{{ $payment->tendered !== null ? $rp($payment->tendered) : '-' }}</td>
                            <td class="fnb-num">{{ (float) $payment->change_amount > 0 ? $rp($payment->change_amount) : '-' }}</td>
                            <td class="fnb-num">{{ (float) $payment->mdr_amount > 0 ? $rp($payment->mdr_amount) : '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    @if ($order->discounts->isNotEmpty())
        <x-filament::section heading="Diskon & promo">
            <table class="fnb-receipt" aria-label="Diskon dan promo">
                <thead>
                    <tr>
                        <th scope="col">Sumber</th>
                        <th scope="col">Berlaku untuk</th>
                        <th scope="col">Nilai</th>
                        <th scope="col">Alasan</th>
                        <th scope="col" class="fnb-num">Potongan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->discounts as $discount)
                        <tr>
                            <td>{{ $discount->source === 'promo' ? 'Promo' : 'Manual' }}{{ $discount->authorized_by ? ' (disetujui supervisor)' : '' }}</td>
                            <td>{{ $discount->order_item_id ? ($order->items->firstWhere('id', $discount->order_item_id)?->name ?? 'Menu') : 'Seluruh transaksi' }}</td>
                            <td>{{ $discount->type === 'percent' ? $qty($discount->value).'%' : $rp($discount->value) }}</td>
                            <td>{{ $discount->reason ?? '-' }}</td>
                            <td class="fnb-num">-{{ $rp($discount->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif

    @if ($order->refunds->isNotEmpty())
        <x-filament::section heading="Refund">
            <table class="fnb-receipt" aria-label="Refund">
                <thead>
                    <tr>
                        <th scope="col">Waktu</th>
                        <th scope="col">Metode</th>
                        <th scope="col">Stok</th>
                        <th scope="col">Alasan</th>
                        <th scope="col" class="fnb-num">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->refunds as $refund)
                        <tr>
                            <td>{{ $refund->device_created_at->timezone($tz)->format('d M Y H.i') }}</td>
                            <td>{{ \App\Filament\Support\SalesLabels::method($refund->method) }}</td>
                            <td>{{ \App\Filament\Support\SalesLabels::STOCK_ACTIONS[$refund->stock_action] ?? $refund->stock_action }}</td>
                            <td>{{ $refund->reason }}</td>
                            <td class="fnb-num">-{{ $rp($refund->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
</div>
