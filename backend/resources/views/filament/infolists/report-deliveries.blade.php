@php
    use App\Modules\Reporting\Domain\Models\ReportDelivery;
    $tz = \App\Modules\Reporting\Application\ReportAccess::timezone();
    $rows = ReportDelivery::query()->where('schedule_id', $getRecord()->getKey())->latest('created_at')->limit(20)->get();
@endphp
@if ($rows->isEmpty())
    <p class="fnb-muted">Belum ada pengiriman.</p>
@else
    <div class="fnb-table-scroll">
        <table class="fnb-receipt" aria-label="Riwayat pengiriman laporan">
            <thead>
                <tr>
                    <th scope="col">Waktu</th>
                    <th scope="col">Periode</th>
                    <th scope="col">Status</th>
                    <th scope="col">Berkas</th>
                    <th scope="col" class="fnb-num">Baris</th>
                    <th scope="col">Keterangan</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $d)
                    <tr>
                        <td>{{ $d->created_at->setTimezone($tz)->format('d/m/Y H.i') }}</td>
                        <td>{{ $d->period_from->format('d/m/Y') }}@if (! $d->period_from->equalTo($d->period_to)) – {{ $d->period_to->format('d/m/Y') }}@endif</td>
                        <td @class(['fnb-negative' => $d->status === 'failed'])>{{ ReportDelivery::STATUSES[$d->status] ?? $d->status }}</td>
                        <td>{{ $d->filename ?? '-' }}</td>
                        <td class="fnb-num">{{ $d->row_count ?? '-' }}</td>
                        <td>{{ $d->error ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
