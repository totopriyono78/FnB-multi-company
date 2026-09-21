@php
    use App\Filament\Prototype\DemoBooks as B;
    $ent = B::entities();
    $pl = B::profitLossTable()['rows'];
    $bs = B::balanceTable();
    $elim = B::eliminations();
    $net = collect($pl)->firstWhere('key', 'net');
    $sales = collect($pl)->firstWhere('key', 'sales');
@endphp

<x-filament-panels::page>
    <div class="pr">
        @include('filament.pages.akuntansi._style')


        <div class="pr-grid pr-grid--4">
            <div class="pr-card"><h4>Pendapatan konsolidasi</h4><div class="pr-big">Rp {{ B::rp($sales['consolidated']) }}</div><div class="pr-sub">Jumlah 4 entitas Rp {{ B::rp($sales['sum']) }}</div></div>
            <div class="pr-card"><h4>Laba bersih konsolidasi</h4><div class="pr-big">Rp {{ B::rp($net['consolidated']) }}</div><div class="pr-sub">Margin {{ number_format($net['consolidated'] / $sales['consolidated'] * 100, 1, ',', '.') }}%</div></div>
            <div class="pr-card"><h4>Nilai dieliminasi</h4><div class="pr-big">Rp {{ B::rp(collect($elim)->sum('amount')) }}</div><div class="pr-sub">{{ count($elim) }} pasangan transaksi antar-entitas</div></div>
            <div class="pr-card"><h4>Kesiapan entitas</h4><div class="pr-big">4 / 4</div><div class="pr-sub">Seluruh entitas sudah mengirim saldo September</div></div>
        </div>

        <div class="pr-section">
            <h3>Kertas kerja konsolidasi — Laba Rugi</h3>
            <p class="pr-desc">{{ B::PERIODE }} · kolom per entitas → penjumlahan → eliminasi → konsolidasi</p>
            <div class="pr-scroll">
                <table class="pr-table pr-kk" style="min-width: 900px;">
                    <thead>
                        <tr>
                            <th>Uraian</th>
                            @foreach ($ent as $e)
                                <th class="pr-num">{{ $e['short'] }}</th>
                            @endforeach
                            <th class="pr-num">Jumlah</th>
                            <th class="pr-num">Eliminasi</th>
                            <th class="pr-num">Konsolidasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pl as $row)
                            <tr class="{{ in_array($row['key'], ['gross', 'expense', 'net'], true) ? 'pr-total' : '' }}">
                                <td>{{ $row['label'] }}</td>
                                @foreach (array_keys($ent) as $e)
                                    <td class="pr-num">{{ B::rp($row['values'][$e]) }}</td>
                                @endforeach
                                <td class="pr-num">{{ B::rp($row['sum']) }}</td>
                                <td class="pr-num">{{ B::rp($row['elim']) }}</td>
                                <td class="pr-num">{{ B::rp($row['consolidated']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pr-section">
            <h3>Kertas kerja konsolidasi — Neraca</h3>
            <p class="pr-desc">Posisi 30 September 2026</p>
            <div class="pr-scroll">
                <table class="pr-table pr-kk" style="min-width: 900px;">
                    <thead>
                        <tr>
                            <th>Uraian</th>
                            @foreach ($ent as $e)
                                <th class="pr-num">{{ $e['short'] }}</th>
                            @endforeach
                            <th class="pr-num">Jumlah</th>
                            <th class="pr-num">Eliminasi</th>
                            <th class="pr-num">Konsolidasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bs as $row)
                            @if ($row['key'] === 'cash')
                                <tr class="pr-head0"><td colspan="{{ count($ent) + 4 }}">ASET</td></tr>
                            @endif
                            @if ($row['key'] === 'ap')
                                <tr class="pr-head0"><td colspan="{{ count($ent) + 4 }}">LIABILITAS &amp; EKUITAS</td></tr>
                            @endif
                            <tr class="{{ $row['group'] === 'total' ? 'pr-total' : '' }}">
                                <td>{{ $row['label'] }}</td>
                                @foreach (array_keys($ent) as $e)
                                    <td class="pr-num">{{ B::rp($row['values'][$e]) }}</td>
                                @endforeach
                                <td class="pr-num">{{ B::rp($row['sum']) }}</td>
                                <td class="pr-num">{{ B::rp($row['elim']) }}</td>
                                <td class="pr-num">{{ B::rp($row['consolidated']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pr-section">
            <h3>Jurnal eliminasi</h3>
            <p class="pr-desc">Dibentuk otomatis dari pasangan transaksi antar-entitas yang sudah bertanda; dapat ditambah jurnal eliminasi manual bila perlu.</p>
            <div class="pr-scroll">
                <table class="pr-table">
                    <thead>
                        <tr>
                            <th>Eliminasi</th>
                            <th>Keterangan</th>
                            <th>Debit</th>
                            <th>Kredit</th>
                            <th class="pr-num">Nilai</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($elim as $e)
                            <tr>
                                <td><strong>{{ $e['label'] }}</strong></td>
                                <td class="pr-wrap">{{ $e['detail'] }}</td>
                                <td>{{ $e['debit'] }}</td>
                                <td>{{ $e['credit'] }}</td>
                                <td class="pr-num">{{ B::rp($e['amount']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="pr-total">
                            <td colspan="4">Jumlah eliminasi</td>
                            <td class="pr-num">{{ B::rp(collect($elim)->sum('amount')) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pr-section">
            <h3>Bagaimana angka ini sampai ke holding</h3>
            <div class="pr-flow">
                <div><b>1. Entitas menutup periode</b>Saldo tiap akun dikunci setelah rekonsiliasi.</div>
                <div><b>2. Saldo ditarik</b>Proses terjadwal menyalin saldo ringkas ke level holding — data transaksi tetap tertutup.</div>
                <div><b>3. Pasangan dicocokkan</b>Hutang di satu entitas dicocokkan dengan piutang di entitas lain.</div>
                <div><b>4. Eliminasi &amp; laporan</b>Kertas kerja tersusun dan laporan konsolidasi siap diekspor.</div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
