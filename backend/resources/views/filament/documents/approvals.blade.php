@php
    /*
     * Jejak tanda tangan (DOC-10). Yang ditampilkan bukan hanya tanda tangan yang sudah masuk, tetapi
     * juga tingkat yang masih menunggu — pemeriksa perlu tahu dokumen ini sedang menunggu siapa, bukan
     * hanya siapa saja yang sudah tanda tangan.
     */
    /** @var \App\Modules\Documents\Domain\Models\PaymentRequest $sppk */
    $sppk = $getRecord();
    $peran = \App\Modules\Documents\Application\ApprovalMatrix::roleOptions();
    $masuk = $sppk->approvals()->with('approver:id,name')->get()->keyBy('level');
    $tingkat = $sppk->status === \App\Modules\Documents\Domain\Models\PaymentRequest::DRAFT
        ? collect()
        : collect(range(1, max(1, $sppk->required_levels)));
@endphp

<x-filament::section heading="Jejak persetujuan">
    @if ($tingkat->isEmpty())
        <p class="fnb-muted">Belum diajukan, jadi belum ada tanda tangan yang dituntut.</p>
    @else
        <ul class="fnb-attachments">
            @foreach ($tingkat as $n)
                @php $tanda = $masuk->get($n); @endphp
                <li>
                    <strong>Tingkat {{ $n }}</strong>
                    @if ($tanda)
                        — {{ $tanda->approver?->name ?? 'pengguna terhapus' }}
                        <span class="fnb-muted">
                            ({{ $peran[$tanda->role] ?? $tanda->role }}) ·
                            {{ $tanda->approved_at?->timezone(config('app.display_timezone'))->translatedFormat('d M Y H.i') }}
                            @if ($tanda->note) · {{ $tanda->note }} @endif
                        </span>
                    @else
                        <span class="fnb-muted">— menunggu tanda tangan</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-filament::section>
