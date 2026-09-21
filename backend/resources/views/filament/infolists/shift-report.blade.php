@php
    $report = $this->report();
    $movements = $this->movements();
    $rp = fn ($v) => \App\Filament\Support\MenuFields::rupiah((string) $v);
    $tz = (string) config('app.display_timezone');
@endphp

<div class="fnb-stack">
    <x-filament::section heading="Rekap penjualan" :description="$report['order_count'].' transaksi, '.$report['void_count'].' dibatalkan'">
        <dl class="fnb-totals">
            <div><dt>Penjualan</dt><dd>{{ $rp($report['sales_total']) }}</dd></div>
            <div><dt>Refund</dt><dd>{{ (float) $report['refund_total'] > 0 ? '-' : '' }}{{ $rp($report['refund_total']) }}</dd></div>
            <div class="fnb-totals__grand"><dt>Total diterima (setelah refund)</dt><dd>{{ $rp($report['net_sales']) }}</dd></div>
            <div><dt>Diskon diberikan</dt><dd>{{ $rp($report['discount_total']) }}</dd></div>
            <div><dt>Pajak</dt><dd>{{ $rp($report['tax_total']) }}</dd></div>
            <div><dt>Service charge</dt><dd>{{ $rp($report['service_charge_total']) }}</dd></div>
            @if ((float) $report['void_after_payment_total'] > 0)
                <div><dt>Void setelah bayar</dt><dd>{{ $rp($report['void_after_payment_total']) }}</dd></div>
            @endif
        </dl>
    </x-filament::section>

    <x-filament::section heading="Per metode pembayaran">
        @if ($report['payments'] === [])
            <p class="fnb-muted">Belum ada pembayaran.</p>
        @else
            <table class="fnb-receipt" aria-label="Pembayaran per metode">
                <thead>
                    <tr>
                        <th scope="col">Metode</th>
                        <th scope="col" class="fnb-num">Jumlah</th>
                        <th scope="col" class="fnb-num">Nominal</th>
                        <th scope="col" class="fnb-num">MDR</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['payments'] as $method => $row)
                        <tr>
                            <td>{{ \App\Filament\Support\SalesLabels::method($method) }}</td>
                            <td class="fnb-num">{{ $row['count'] }}</td>
                            <td class="fnb-num">{{ $rp($row['amount']) }}</td>
                            <td class="fnb-num">{{ $rp($row['mdr']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    <x-filament::section heading="Kas di laci">
        <dl class="fnb-totals">
            <div><dt>Modal awal</dt><dd>{{ $rp($report['cash']['opening']) }}</dd></div>
            <div><dt>Penjualan tunai</dt><dd>{{ $rp($report['cash']['sales']) }}</dd></div>
            <div><dt>Kas masuk</dt><dd>{{ $rp($report['cash']['in']) }}</dd></div>
            <div><dt>Kas keluar</dt><dd>{{ (float) $report['cash']['out'] > 0 ? '-' : '' }}{{ $rp($report['cash']['out']) }}</dd></div>
            <div><dt>Refund tunai</dt><dd>{{ (float) $report['cash']['refunds'] > 0 ? '-' : '' }}{{ $rp($report['cash']['refunds']) }}</dd></div>
            <div class="fnb-totals__grand"><dt>Kas seharusnya</dt><dd>{{ $rp($report['cash']['expected']) }}</dd></div>
        </dl>

        @if ($movements->isNotEmpty())
            <table class="fnb-receipt" aria-label="Pergerakan kas">
                <thead>
                    <tr>
                        <th scope="col">Waktu</th>
                        <th scope="col">Jenis</th>
                        <th scope="col">Alasan</th>
                        <th scope="col" class="fnb-num">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($movements as $m)
                        <tr>
                            <td>{{ $m->device_created_at->timezone($tz)->format('d M Y H.i') }}</td>
                            <td>{{ \App\Filament\Support\SalesLabels::CASH_TYPES[$m->type] ?? $m->type }}{{ $m->authorized_by ? ' (disetujui supervisor)' : '' }}</td>
                            <td>{{ $m->reason }}</td>
                            <td class="fnb-num">{{ $m->type === 'drawer_open' ? '-' : $rp($m->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</div>
