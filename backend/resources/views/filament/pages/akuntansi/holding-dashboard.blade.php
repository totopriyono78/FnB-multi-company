@php
    use App\Filament\Prototype\DemoBooks as B;
    $ent = B::entities();
    $pl = B::profitLossTable();
    $rowByKey = collect($pl['rows'])->keyBy('key');
    $sales = $rowByKey['sales'];
    $net = $rowByKey['net'];
    $ready = B::readiness();
    $docs = B::documents();
    $waiting = collect($docs)->whereIn('status', ['Menunggu verifikasi', 'Dikembalikan'])->count();
    $chip = fn (string $s) => match ($s) {
        'ok' => '<span class="pr-chip pr-chip--ok">Lengkap</span>',
        'warn' => '<span class="pr-chip pr-chip--wait">Tertunda</span>',
        default => '<span class="pr-chip pr-chip--bad">Belum</span>',
    };
@endphp

<x-filament-panels::page>
    <div class="pr">
        @include('filament.pages.akuntansi._style')


        <div class="pr-grid pr-grid--4">
            <div class="pr-card">
                <h4>Pendapatan konsolidasi</h4>
                <div class="pr-big">Rp {{ B::rp($sales['consolidated']) }}</div>
                <div class="pr-sub">{{ B::PERIODE }} · setelah eliminasi Rp {{ B::rp(-$sales['elim']) }}</div>
            </div>
            <div class="pr-card">
                <h4>Laba bersih konsolidasi</h4>
                <div class="pr-big">Rp {{ B::rp($net['consolidated']) }}</div>
                <div class="pr-sub">Margin {{ number_format($net['consolidated'] / $sales['consolidated'] * 100, 1, ',', '.') }}%</div>
            </div>
            <div class="pr-card">
                <h4>Entitas dalam grup</h4>
                <div class="pr-big">{{ count($ent) }}</div>
                <div class="pr-sub">{{ B::HOLDING }} · data tiap entitas tertutup satu sama lain</div>
            </div>
            <div class="pr-card">
                <h4>Dokumen menunggu tindakan</h4>
                <div class="pr-big">{{ $waiting }}</div>
                <div class="pr-sub">SPPK &amp; advis yang menunggu verifikasi pusat</div>
            </div>
        </div>

        <div class="pr-section">
            <h3>Ringkasan per entitas</h3>
            <p class="pr-desc">Setiap cabang adalah badan usaha sendiri. Angka di bawah dikumpulkan ke holding sebagai saldo ringkas, bukan dengan membuka akses lintas entitas.</p>
            <div class="pr-scroll">
                <table class="pr-table">
                    <thead>
                        <tr>
                            <th>Entitas</th>
                            <th>Jenis</th>
                            <th>Kota</th>
                            <th class="pr-num">Pendapatan</th>
                            <th class="pr-num">Laba bersih</th>
                            <th class="pr-num">Margin</th>
                            <th>Penjualan</th>
                            <th>Dokumen</th>
                            <th>Bank</th>
                            <th>Entry terakhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ready as $r)
                            @php $e = $r['entity']; @endphp
                            <tr>
                                <td><strong>{{ $ent[$e]['name'] }}</strong><br><span class="pr-lock">{{ $ent[$e]['short'] }}</span></td>
                                <td>{{ $ent[$e]['kind'] }}</td>
                                <td>{{ $ent[$e]['city'] }}</td>
                                <td class="pr-num">{{ B::rp($sales['values'][$e]) }}</td>
                                <td class="pr-num">{{ B::rp($net['values'][$e]) }}</td>
                                <td class="pr-num">{{ number_format($net['values'][$e] / $sales['values'][$e] * 100, 1, ',', '.') }}%</td>
                                <td>{!! $chip($r['sales']) !!}</td>
                                <td>{!! $chip($r['docs']) !!} @if ($r['pending'] > 0)<span class="pr-lock">{{ $r['pending'] }} tertunda</span>@endif</td>
                                <td>{!! $chip($r['bank']) !!}</td>
                                <td><span class="pr-lock">{{ $r['last'] }}</span></td>
                            </tr>
                        @endforeach
                        <tr class="pr-total">
                            <td colspan="3">Jumlah 4 entitas</td>
                            <td class="pr-num">{{ B::rp($sales['sum']) }}</td>
                            <td class="pr-num">{{ B::rp($net['sum']) }}</td>
                            <td colspan="5"></td>
                        </tr>
                        <tr class="pr-total">
                            <td colspan="3">Setelah eliminasi antar-entitas</td>
                            <td class="pr-num">{{ B::rp($sales['consolidated']) }}</td>
                            <td class="pr-num">{{ B::rp($net['consolidated']) }}</td>
                            <td colspan="5"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pr-section">
            <h3>Kelengkapan harian — kunci laporan keuangan tiap 2 hari</h3>
            <p class="pr-desc">Laporan hanya dapat terbit cepat bila tiga hal ini beres setiap hari di semua entitas.</p>
            <div class="pr-flow">
                <div><b>1. Penjualan terjurnal</b>Otomatis dari tutup hari POS, atau dari impor rekap untuk entitas yang belum pindah sistem.</div>
                <div><b>2. Nota &amp; biaya diverifikasi</b>SPPK dan advis bayar dengan lampiran foto, diperiksa pusat pada hari yang sama.</div>
                <div><b>3. Bank direkonsiliasi</b>Mutasi bank diimpor dan dicocokkan; sisa yang belum cocok terlihat jelas.</div>
                <div><b>4. Laporan terbit</b>Neraca, laba rugi, dan konsolidasi dapat dibuat kapan saja atas angka yang sudah lengkap.</div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
