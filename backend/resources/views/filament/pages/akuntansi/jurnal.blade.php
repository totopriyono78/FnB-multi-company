@php
    use App\Filament\Prototype\DemoBooks as B;
    $ent = B::entities();
    $journals = B::journals();
    $pick = request()->query('jurnal');
    $selected = collect($journals)->firstWhere('no', $pick) ?? $journals[0];
    $ledger = B::ledger();
    $statusChip = fn (string $s) => match ($s) {
        'Diposting' => 'pr-chip--ok',
        'Menunggu verifikasi' => 'pr-chip--wait',
        'Draft' => 'pr-chip--mute',
        'Ditolak' => 'pr-chip--bad',
        default => 'pr-chip--info',
    };
@endphp

<x-filament-panels::page>
    <div class="pr">
        @include('filament.pages.akuntansi._style')


        <div class="pr-section" style="margin-top:0">
            <h3>Jurnal umum — {{ B::PERIODE }}</h3>
            <p class="pr-desc">Klik nomor jurnal untuk melihat rinciannya di bawah.</p>
            <div class="pr-scroll">
                <table class="pr-table">
                    <thead>
                        <tr>
                            <th>No. jurnal</th>
                            <th>Tanggal</th>
                            <th>Entitas</th>
                            <th>Sumber</th>
                            <th>Keterangan</th>
                            <th class="pr-num">Nilai</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($journals as $j)
                            <tr @if ($j['no'] === $selected['no']) style="background: color-mix(in srgb, var(--pr-brand) 8%, transparent);" @endif>
                                <td class="pr-code"><a href="?jurnal={{ $j['no'] }}" style="text-decoration: underline;">{{ $j['no'] }}</a></td>
                                <td>{{ $j['date'] }}</td>
                                <td>{{ $ent[$j['entity']]['short'] }}</td>
                                <td>{{ $j['source'] }}</td>
                                <td class="pr-wrap">{{ $j['memo'] }}</td>
                                <td class="pr-num">{{ B::rp($j['amount']) }}</td>
                                <td><span class="pr-chip {{ $statusChip($j['status']) }}">{{ $j['status'] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pr-section">
            <h3>Rincian {{ $selected['no'] }}</h3>
            <p class="pr-desc">{{ $selected['memo'] }} · {{ $ent[$selected['entity']]['name'] }} · {{ $selected['date'] }} · sumber: {{ $selected['source'] }}</p>
            <div class="pr-scroll">
                <table class="pr-table">
                    <thead>
                        <tr>
                            <th style="width: 7rem;">Kode akun</th>
                            <th>Nama akun</th>
                            <th class="pr-num" style="width: 10rem;">Debit</th>
                            <th class="pr-num" style="width: 10rem;">Kredit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $d = 0; $k = 0; @endphp
                        @foreach ($selected['lines'] as $line)
                            @php $d += $line[2]; $k += $line[3]; @endphp
                            <tr>
                                <td class="pr-code">{{ $line[0] }}</td>
                                <td class="pr-wrap">{{ $line[1] }}</td>
                                <td class="pr-num">{{ B::rp($line[2]) }}</td>
                                <td class="pr-num">{{ B::rp($line[3]) }}</td>
                            </tr>
                        @endforeach
                        <tr class="pr-total">
                            <td colspan="2">Jumlah — seimbang</td>
                            <td class="pr-num">{{ B::rp($d) }}</td>
                            <td class="pr-num">{{ B::rp($k) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pr-section">
            <h3>Buku besar — 1-1200 Bank Operasional · {{ $ent['A']['name'] }}</h3>
            <p class="pr-desc">Setiap baris dapat ditelusuri kembali ke jurnal, dokumen, dan lampirannya.</p>
            <div class="pr-scroll">
                <table class="pr-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Keterangan</th>
                            <th>Referensi</th>
                            <th class="pr-num">Debit</th>
                            <th class="pr-num">Kredit</th>
                            <th class="pr-num">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ledger as $row)
                            <tr class="{{ $row['opening'] ? 'pr-sub' : '' }}">
                                <td>{{ $row['date'] }}</td>
                                <td class="pr-wrap">{{ $row['memo'] }}</td>
                                <td class="pr-code">{{ $row['ref'] }}</td>
                                <td class="pr-num">{{ B::rp($row['debit']) }}</td>
                                <td class="pr-num">{{ B::rp($row['credit']) }}</td>
                                <td class="pr-num">{{ B::rp($row['balance']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="pr-total">
                            <td colspan="5">Saldo akhir 30 September 2026</td>
                            <td class="pr-num">{{ B::rp(end($ledger)['balance']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
