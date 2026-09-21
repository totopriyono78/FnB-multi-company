@php
    use App\Filament\Prototype\DemoBooks as B;
    $coa = B::chartOfAccounts();
    $locked = collect($coa)->where('locked', true)->count();
    $local = count($coa) - $locked;
@endphp

<x-filament-panels::page>
    <div class="pr">
        @include('filament.pages.akuntansi._style')


        <div class="pr-grid pr-grid--4">
            <div class="pr-card"><h4>Akun dalam bagan induk</h4><div class="pr-big">{{ count($coa) }}</div><div class="pr-sub">Template F&amp;B — dipakai seluruh entitas</div></div>
            <div class="pr-card"><h4>Terkunci (induk)</h4><div class="pr-big">{{ $locked }}</div><div class="pr-sub">Tidak dapat diubah dari cabang</div></div>
            <div class="pr-card"><h4>Sub-akun lokal</h4><div class="pr-big">{{ $local }}</div><div class="pr-sub">Ditambahkan entitas pada rentang yang diizinkan</div></div>
            <div class="pr-card"><h4>Versi bagan akun</h4><div class="pr-big">v3 · 2026</div><div class="pr-sub">Perubahan berikutnya membuat versi baru + jejak audit</div></div>
        </div>

        <div class="pr-section">
            <h3>Distribusi ke entitas</h3>
            <div class="pr-flow">
                <div><b>Holding menyusun</b>Satu daftar akun standar untuk seluruh grup.</div>
                <div><b>Dibagikan otomatis</b>Tiap entitas menerima salinan aktif dengan kode yang sama persis.</div>
                <div><b>Cabang memakai</b>Boleh menonaktifkan akun yang tak terpakai dan menambah sub-akun lokal.</div>
                <div><b>Konsolidasi rapi</b>Karena kodenya seragam, saldo antar entitas dapat langsung dijumlahkan.</div>
            </div>
        </div>

        <div class="pr-section">
            <h3>Bagan akun standar — {{ B::HOLDING }}</h3>
            <p class="pr-desc">Akun bertanda gembok dikelola pusat. Akun bertanda "lokal" ditambahkan oleh entitas tertentu.</p>
            <div class="pr-scroll" style="max-height: 30rem; overflow-y: auto;">
                <table class="pr-table">
                    <thead>
                        <tr>
                            <th style="width: 7rem;">Kode</th>
                            <th>Nama akun</th>
                            <th style="width: 8rem;">Kelompok</th>
                            <th style="width: 8rem;">Status</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($coa as $a)
                            <tr class="{{ $a['level'] === 0 ? 'pr-head0' : '' }}">
                                <td class="pr-code">{{ $a['code'] }}</td>
                                <td class="pr-indent-{{ $a['level'] }}">{{ $a['name'] }}</td>
                                <td>{{ $a['type'] }}</td>
                                <td>
                                    @if ($a['locked'])
                                        <span class="pr-chip pr-chip--mute">🔒 Induk</span>
                                    @else
                                        <span class="pr-chip pr-chip--info">Lokal entitas</span>
                                    @endif
                                </td>
                                <td class="pr-wrap"><span class="pr-lock">{{ $a['note'] ?? '' }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
