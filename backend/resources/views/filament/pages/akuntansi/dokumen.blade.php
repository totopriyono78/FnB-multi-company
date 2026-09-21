@php
    use App\Filament\Prototype\DemoBooks as B;
    $ent = B::entities();
    $docs = B::documents();
    $pick = request()->query('dok');
    $sel = collect($docs)->firstWhere('no', $pick) ?? $docs[0];
    $trail = B::documentTrail();
    $chip = fn (string $s) => match ($s) {
        'Dibayar' => 'pr-chip--ok',
        'Diverifikasi' => 'pr-chip--info',
        'Menunggu verifikasi' => 'pr-chip--wait',
        'Dikembalikan' => 'pr-chip--bad',
        default => 'pr-chip--mute',
    };
    $short = ['Menunggu verifikasi' => 'Menunggu', 'Dikembalikan' => 'Dikembalikan', 'Diverifikasi' => 'Diverifikasi', 'Dibayar' => 'Dibayar', 'Terkirim' => 'Terkirim'];
    $menunggu = collect($docs)->where('status', 'Menunggu verifikasi')->count();
    $nilaiMenunggu = collect($docs)->where('status', 'Menunggu verifikasi')->sum('amount');
    $lampiran = collect($docs)->sum('files');
@endphp

<x-filament-panels::page>
    <div class="pr">
        @include('filament.pages.akuntansi._style')


        <div class="pr-grid pr-grid--4">
            <div class="pr-card"><h4>Menunggu verifikasi</h4><div class="pr-big">{{ $menunggu }}</div><div class="pr-sub">Senilai Rp {{ B::rp($nilaiMenunggu) }}</div></div>
            <div class="pr-card"><h4>Dokumen bulan ini</h4><div class="pr-big">{{ count($docs) }}</div><div class="pr-sub">SPPK, advis bayar, dan advis tagih</div></div>
            <div class="pr-card"><h4>Lampiran tersimpan</h4><div class="pr-big">{{ $lampiran }}</div><div class="pr-sub">Foto nota, bukti transfer, surat jalan</div></div>
            <div class="pr-card"><h4>Rata-rata waktu verifikasi</h4><div class="pr-big">4,2 jam</div><div class="pr-sub">Dari diajukan sampai disetujui</div></div>
        </div>

        <div class="pr-section">
            <h3>Alur dokumen</h3>
            <div class="pr-flow">
                <div><b>1. Diajukan</b>Cabang mengisi penerima, nilai, akun beban, dan melampirkan foto nota / bukti transfer.</div>
                <div><b>2. Diverifikasi</b>Pusat memeriksa; dapat menyetujui, mengembalikan dengan catatan, atau menolak.</div>
                <div><b>3. Dibayar</b>Advis bayar menentukan bank sumber dan tanggal; bukti transfer dilampirkan.</div>
                <div><b>4. Terjurnal</b>Jurnal terbentuk otomatis dan tertaut ke dokumen beserta lampirannya.</div>
            </div>
        </div>

        <div class="pr-section">
            <h3>Antrian dokumen</h3>
            <p class="pr-desc">Klik nomor dokumen untuk melihat jejak persetujuannya.</p>
            <div class="pr-scroll">
                <table class="pr-table pr-tight">
                    <thead>
                        <tr>
                            <th>Nomor</th>
                            <th>Jenis</th>
                            <th>Entitas</th>
                            <th>Penerima / tujuan</th>
                            <th>Akun</th>
                            <th class="pr-num">Nilai</th>
                            <th>Lampiran</th>
                            <th>Jatuh tempo</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($docs as $d)
                            <tr @if ($d['no'] === $sel['no']) style="background: color-mix(in srgb, var(--pr-brand) 8%, transparent);" @endif>
                                <td class="pr-code"><a href="?dok={{ $d['no'] }}" style="text-decoration: underline;">{{ $d['no'] }}</a></td>
                                <td>{{ $d['type'] }}</td>
                                <td>{{ $ent[$d['entity']]['short'] }}</td>
                                <td class="pr-wrap"><strong>{{ $d['payee'] }}</strong><br><span class="pr-lock">{{ $d['purpose'] }}</span></td>
                                <td class="pr-code">{{ $d['account'] }}</td>
                                <td class="pr-num">{{ B::rp($d['amount']) }}</td>
                                <td>📎 {{ $d['files'] }}</td>
                                <td>{{ $d['due'] }}</td>
                                <td><span class="pr-chip {{ $chip($d['status']) }}">{{ $short[$d['status']] ?? $d['status'] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pr-grid pr-grid--2 pr-section">
            <div class="pr-card">
                <h4>Rincian dokumen</h4>
                <div style="font-size:.85rem; line-height:1.7">
                    <div class="pr-code" style="font-size:.9rem"><strong>{{ $sel['no'] }}</strong></div>
                    <div>{{ $sel['type'] }} · {{ $ent[$sel['entity']]['name'] }} · {{ $sel['date'] }}</div>
                    <div><strong>{{ $sel['payee'] }}</strong> — {{ $sel['purpose'] }}</div>
                    <div>Akun: <span class="pr-code">{{ $sel['account'] }}</span></div>
                    <div>Nilai: <strong>Rp {{ B::rp($sel['amount']) }}</strong> · jatuh tempo {{ $sel['due'] }}</div>
                    <div>Pembuat: {{ $sel['maker'] }}</div>
                    <div>Verifikator: {{ $sel['checker'] }}</div>
                    <div>Lampiran: 📎 {{ $sel['files'] }} berkas (foto nota, bukti transfer)</div>
                    <div style="margin-top:.5rem"><span class="pr-chip {{ $chip($sel['status']) }}">{{ $sel['status'] }}</span></div>
                </div>
            </div>
            <div class="pr-card">
                <h4>Jejak persetujuan</h4>
                <ul class="pr-trail">
                    @foreach ($trail as $t)
                        <li>
                            <span class="pr-dot {{ $t['time'] === '—' ? 'pr-dot--wait' : '' }}"></span>
                            <div>
                                <strong>{{ $t['action'] }}</strong> — {{ $t['actor'] }}
                                <span class="pr-lock">{{ $t['note'] }}</span>
                                <time>{{ $t['time'] }}</time>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="pr-section">
            <h3>Dua pola kerja cabang</h3>
            <div class="pr-grid pr-grid--2">
                <div class="pr-card">
                    <h4>Cabang punya staf finance</h4>
                    <div style="font-size:.82rem; line-height:1.6">Cabang membuat dokumen <em>dan</em> mengisi kode akun sendiri;
                        pusat tinggal memverifikasi. Contoh: Resto Kemang (Siti Rahayu) dan Resto Dago (Bagus Nugroho).</div>
                </div>
                <div class="pr-card">
                    <h4>Cabang tanpa staf finance</h4>
                    <div style="font-size:.82rem; line-height:1.6">Cabang cukup mengunggah foto nota; entry dan verifikasi
                        keduanya dikerjakan pusat dengan dua orang berbeda. Contoh: Villa Ubud dan Retail.</div>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
