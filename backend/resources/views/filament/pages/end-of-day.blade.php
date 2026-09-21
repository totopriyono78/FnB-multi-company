@php
    $preview = $this->preview();
    $rp = fn ($v) => \App\Filament\Support\MenuFields::rupiah((string) $v);
    $tz = (string) config('app.display_timezone');
@endphp

<x-filament-panels::page>
    <div class="fnb-availability-toolbar">
        {{ $this->form }}
        <p class="fnb-muted">
            Tutup hari hanya bisa dilakukan setelah semua shift kasir pada hari bisnis tersebut ditutup.
        </p>
    </div>

    @if ($preview)
        @if ($preview['closed'])
            <div class="fnb-callout" role="status">
                <p class="fnb-callout__title">Hari bisnis sudah ditutup</p>
                <p>Ditutup {{ $preview['closed_record']?->closed_at->timezone($tz)->format('d M Y H.i') }}.
                    @if (! empty($preview['closed_record']?->summary['note']))
                        Catatan: {{ $preview['closed_record']->summary['note'] }}
                    @endif
                </p>
            </div>
        @elseif ($preview['open_shifts'] !== [])
            <div class="fnb-callout fnb-callout--danger" role="alert">
                <p class="fnb-callout__title">Masih ada {{ count($preview['open_shifts']) }} shift terbuka</p>
                <ul class="fnb-callout__list">
                    @foreach ($preview['open_shifts'] as $shift)
                        <li>{{ $shift['cashier'] ?? 'Kasir' }} — dibuka {{ \Carbon\CarbonImmutable::parse($shift['opened_at'])->timezone($tz)->format('d M Y H.i') }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @php($s = $preview['closed'] && $preview['closed_record'] ? $preview['closed_record']->summary : $preview['summary'])
        <x-filament::section heading="Ringkasan hari bisnis" :description="$s['order_count'].' transaksi · '.$s['void_count'].' dibatalkan · '.$s['shift_count'].' shift'">
            <dl class="fnb-totals">
                <div><dt>Penjualan kotor</dt><dd>{{ $rp($s['gross_total']) }}</dd></div>
                <div><dt>Diskon</dt><dd>{{ (float) $s['discount_total'] > 0 ? '-' : '' }}{{ $rp($s['discount_total']) }}</dd></div>
                <div><dt>Service charge</dt><dd>{{ $rp($s['service_charge_total']) }}</dd></div>
                <div><dt>Pajak</dt><dd>{{ $rp($s['tax_total']) }}</dd></div>
                <div><dt>Pembulatan</dt><dd>{{ $rp($s['rounding_total']) }}</dd></div>
                <div><dt>Total penjualan</dt><dd>{{ $rp($s['sales_total']) }}</dd></div>
                <div><dt>Refund ({{ $s['refund_count'] }})</dt><dd>{{ (float) $s['refund_total'] > 0 ? '-' : '' }}{{ $rp($s['refund_total']) }}</dd></div>
                <div class="fnb-totals__grand"><dt>Total diterima (setelah refund)</dt><dd>{{ $rp($s['net_sales']) }}</dd></div>
                <div><dt>Selisih kas semua shift</dt><dd>{{ $rp($s['cash_variance_total']) }}</dd></div>
                <div><dt>Transaksi perlu ditinjau</dt><dd>{{ $s['flagged_count'] }}</dd></div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Per metode pembayaran">
            @if ($s['payments'] === [])
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
                        @foreach ($s['payments'] as $method => $row)
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
    @else
        <p class="fnb-muted">Pilih outlet dan hari bisnis.</p>
    @endif
</x-filament-panels::page>
