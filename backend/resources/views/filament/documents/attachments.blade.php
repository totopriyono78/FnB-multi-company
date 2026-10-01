@php
    /*
     * Daftar lampiran satu dokumen apa pun — jurnal, SPPK, atau advis bayar. Jenis pemiliknya
     * diberikan lewat viewData('ownerType'), sehingga satu tampilan ini dipakai bersama dan daftar
     * bukti terlihat sama di mana pun ia muncul.
     */
    $ownerType = $ownerType ?? \App\Modules\Shared\Domain\Models\DocumentAttachment::JOURNAL;
    $lampiran = app(\App\Modules\Shared\Application\DocumentAttachments::class)
        ->forOwner($ownerType, $getRecord()->id);
@endphp

<x-filament::section heading="Lampiran bukti" collapsible :collapsed="$lampiran->isEmpty()">
    @if ($lampiran->isEmpty())
        {{-- Dikatakan apa adanya: dokumen tanpa bukti bukan kesalahan, tetapi juga bukan hal yang
             boleh luput dari perhatian pemeriksa. --}}
        <p class="fnb-muted">Belum ada bukti yang dilampirkan pada dokumen ini.</p>
    @else
        <ul class="fnb-attachments">
            @foreach ($lampiran as $berkas)
                <li>
                    <a href="{{ route('accounting.attachment', $berkas->id) }}" target="_blank" rel="noopener">
                        {{ $berkas->original_name }}
                    </a>
                    <span class="fnb-muted">{{ $berkas->sizeLabel() }} · diunggah {{ $berkas->created_at->timezone(config('app.display_timezone'))->translatedFormat('d M Y H.i') }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament::section>
