@php
    use App\Filament\Prototype\DemoBooks as B;
    $ent = B::entities();
    $key = request()->query('entitas');
    if (! array_key_exists((string) $key, $ent)) {
        $key = 'A';
    }
    $pl = B::profitLossTable()['rows'];
    $bs = B::balanceTable();
    $val = fn (array $row) => $row['values'][$key];
    $net = collect($pl)->firstWhere('key', 'net');
    $sales = collect($pl)->firstWhere('key', 'sales');
    $totalAsset = collect($bs)->firstWhere('key', 'total_asset');
@endphp

<x-filament-panels::page>
    <div class="pr">
        @include('filament.pages.akuntansi._style')


        <div class="pr-tabs">
            @foreach ($ent as $k => $e)
                <a class="pr-tab {{ $k === $key ? 'pr-tab--on' : '' }}" href="?entitas={{ $k }}">{{ $e['name'] }}</a>
            @endforeach
        </div>

        <div class="pr-grid pr-grid--4">
            <div class="pr-card"><h4>Pendapatan</h4><div class="pr-big">Rp {{ B::rp($val($sales)) }}</div><div class="pr-sub">{{ B::PERIODE }}</div></div>
            <div class="pr-card"><h4>Laba bersih</h4><div class="pr-big">Rp {{ B::rp($val($net)) }}</div><div class="pr-sub">Margin {{ number_format($val($net) / $val($sales) * 100, 1, ',', '.') }}%</div></div>
            <div class="pr-card"><h4>Jumlah aset</h4><div class="pr-big">Rp {{ B::rp($val($totalAsset)) }}</div><div class="pr-sub">Posisi 30 September 2026</div></div>
            <div class="pr-card"><h4>Status periode</h4><div class="pr-big">Tutup sementara</div><div class="pr-sub">Angka indikatif — tutup final setelah rekonsiliasi bank</div></div>
        </div>

        <div class="pr-grid pr-grid--2 pr-section">
            <div>
                <h3 style="font-size:.95rem; font-weight:700; margin:0 0 .15rem;">Laba Rugi — {{ $ent[$key]['name'] }}</h3>
                <p class="pr-desc">{{ B::PERIODE }}</p>
                <div class="pr-scroll">
                    <table class="pr-table" style="min-width: 340px;">
                        <thead><tr><th>Uraian</th><th class="pr-num">Jumlah (Rp)</th></tr></thead>
                        <tbody>
                            @foreach ($pl as $row)
                                <tr class="{{ in_array($row['key'], ['gross', 'expense', 'net'], true) ? 'pr-total' : '' }}">
                                    <td>{{ $row['label'] }}</td>
                                    <td class="pr-num">{{ B::rp($val($row)) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div>
                <h3 style="font-size:.95rem; font-weight:700; margin:0 0 .15rem;">Neraca — {{ $ent[$key]['name'] }}</h3>
                <p class="pr-desc">Posisi 30 September 2026</p>
                <div class="pr-scroll">
                    <table class="pr-table" style="min-width: 340px;">
                        <thead><tr><th>Uraian</th><th class="pr-num">Jumlah (Rp)</th></tr></thead>
                        <tbody>
                            @foreach ($bs as $row)
                                @if ($row['key'] === 'ap')
                                    <tr class="pr-head0"><td colspan="2">LIABILITAS &amp; EKUITAS</td></tr>
                                @endif
                                @if ($row['key'] === 'cash')
                                    <tr class="pr-head0"><td colspan="2">ASET</td></tr>
                                @endif
                                <tr class="{{ $row['group'] === 'total' ? 'pr-total' : '' }}">
                                    <td>{{ $row['label'] }}</td>
                                    <td class="pr-num">{{ B::rp($val($row)) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="pr-section">
            <h3>Laporan lain yang tersedia di modul ini</h3>
            <div class="pr-grid pr-grid--3">
                <div class="pr-card"><h4>Arus kas</h4><div style="font-size:.8rem">Metode tidak langsung, dari laba bersih ke perubahan kas.</div></div>
                <div class="pr-card"><h4>Buku besar &amp; neraca saldo</h4><div style="font-size:.8rem">Per akun dan per dimensi, dengan penelusuran ke dokumen asal.</div></div>
                <div class="pr-card"><h4>Laba rugi per outlet / brand</h4><div style="font-size:.8rem">Memakai dimensi yang melekat pada setiap jurnal.</div></div>
                <div class="pr-card"><h4>Umur hutang &amp; piutang</h4><div style="font-size:.8rem">Beserta jadwal jatuh tempo dan pengingat.</div></div>
                <div class="pr-card"><h4>Rekap pajak</h4><div style="font-size:.8rem">PPN keluaran dari data penjualan; faktur masukan dari dokumen pembelian.</div></div>
                <div class="pr-card"><h4>Paket laporan terjadwal</h4><div style="font-size:.8rem">Dikirim otomatis tiap 2 hari ke email penerima dalam Excel &amp; PDF.</div></div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
