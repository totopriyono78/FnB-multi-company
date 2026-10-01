@php
    /** @var \App\Modules\Accounting\Domain\Models\Journal $record */
    $lampiran = $getRecord()->attachments;
@endphp

<x-filament::section heading="Lampiran bukti" collapsible :collapsed="$lampiran->isEmpty()">
    @if ($lampiran->isEmpty())
        {{-- Dikatakan apa adanya: jurnal tanpa bukti bukan kesalahan, tetapi juga bukan hal yang
             boleh luput dari perhatian pemeriksa. --}}
        <p class="fnb-muted">Belum ada bukti yang dilampirkan pada jurnal ini.</p>
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
