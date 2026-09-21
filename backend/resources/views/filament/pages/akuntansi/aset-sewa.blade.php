@php
    use App\Filament\Prototype\DemoBooks as B;
    $ent = B::entities();
    $data = B::assets();
    $assets = $data['assets'];
    $leases = $data['leases'];
    $ins = B::instalments();
    $totalCost = collect($assets)->sum('cost');
    $totalBook = collect($assets)->sum('book');
    $monthly = collect($assets)->sum('monthly');
@endphp

<x-filament-panels::page>
    <div class="pr">
        @include('filament.pages.akuntansi._style')


        <div class="pr-grid pr-grid--4">
            <div class="pr-card"><h4>Nilai perolehan</h4><div class="pr-big">Rp {{ B::rp($totalCost) }}</div><div class="pr-sub">{{ count($assets) }} aset terdaftar</div></div>
            <div class="pr-card"><h4>Nilai buku</h4><div class="pr-big">Rp {{ B::rp($totalBook) }}</div><div class="pr-sub">Posisi 30 September 2026</div></div>
            <div class="pr-card"><h4>Penyusutan / bulan</h4><div class="pr-big">Rp {{ B::rp($monthly) }}</div><div class="pr-sub">Dijurnal otomatis akhir bulan</div></div>
            <div class="pr-card"><h4>Kontrak aktif</h4><div class="pr-big">{{ count($leases) }}</div><div class="pr-sub">Sewa tempat &amp; pembiayaan aset</div></div>
        </div>

        <div class="pr-section">
            <h3>Register aset</h3>
            <div class="pr-scroll">
                <table class="pr-table" style="min-width: 900px;">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Nama aset</th>
                            <th>Entitas</th>
                            <th>Perolehan</th>
                            <th class="pr-num">Nilai perolehan</th>
                            <th>Umur</th>
                            <th>Metode</th>
                            <th class="pr-num">Penyusutan/bulan</th>
                            <th class="pr-num">Nilai buku</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($assets as $a)
                            <tr>
                                <td class="pr-code">{{ $a['code'] }}</td>
                                <td class="pr-wrap">{{ $a['name'] }}</td>
                                <td>{{ $ent[$a['entity']]['short'] }}</td>
                                <td>{{ $a['acquired'] }}</td>
                                <td class="pr-num">{{ B::rp($a['cost']) }}</td>
                                <td>{{ $a['life'] }}</td>
                                <td>{{ $a['method'] }}</td>
                                <td class="pr-num">{{ B::rp($a['monthly']) }}</td>
                                <td class="pr-num">{{ B::rp($a['book']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="pr-total">
                            <td colspan="4">Jumlah</td>
                            <td class="pr-num">{{ B::rp($totalCost) }}</td>
                            <td colspan="2"></td>
                            <td class="pr-num">{{ B::rp($monthly) }}</td>
                            <td class="pr-num">{{ B::rp($totalBook) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pr-section">
            <h3>Kontrak sewa &amp; hutang aset</h3>
            <div class="pr-scroll">
                <table class="pr-table" style="min-width: 820px;">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Kontrak</th>
                            <th>Jenis</th>
                            <th>Entitas</th>
                            <th>Periode</th>
                            <th>Termin</th>
                            <th class="pr-num">Nilai kontrak</th>
                            <th class="pr-num">Beban/bulan</th>
                            <th>Jatuh tempo berikut</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($leases as $l)
                            <tr>
                                <td class="pr-code">{{ $l['code'] }}</td>
                                <td class="pr-wrap">{{ $l['name'] }}</td>
                                <td>{{ $l['kind'] }}</td>
                                <td>{{ $ent[$l['entity']]['short'] }}</td>
                                <td>{{ $l['period'] }}</td>
                                <td>{{ $l['term'] }}</td>
                                <td class="pr-num">{{ B::rp($l['value']) }}</td>
                                <td class="pr-num">{{ B::rp($l['monthly']) }}</td>
                                <td>{{ $l['next'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pr-section">
            <h3>Jadwal angsuran — HTA-C-0001 pembiayaan kendaraan</h3>
            <p class="pr-desc">Setiap angsuran dipecah menjadi pokok dan bunga, lalu dijurnal otomatis pada tanggal jatuh tempo.</p>
            <div class="pr-scroll">
                <table class="pr-table" style="min-width: 640px;">
                    <thead>
                        <tr>
                            <th>Ke-</th>
                            <th>Tanggal</th>
                            <th class="pr-num">Angsuran</th>
                            <th class="pr-num">Bunga</th>
                            <th class="pr-num">Pokok</th>
                            <th class="pr-num">Sisa hutang</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ins as $r)
                            <tr>
                                <td>{{ $r['no'] }}</td>
                                <td>{{ $r['date'] }}</td>
                                <td class="pr-num">{{ B::rp($r['instalment']) }}</td>
                                <td class="pr-num">{{ B::rp($r['interest']) }}</td>
                                <td class="pr-num">{{ B::rp($r['principal']) }}</td>
                                <td class="pr-num">{{ B::rp($r['balance']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
