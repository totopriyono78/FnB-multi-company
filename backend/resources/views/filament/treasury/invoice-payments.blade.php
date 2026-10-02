@php
    /*
     * Pelunasan yang sudah dialokasikan ke faktur ini. Nomor advis bayarnya ikut ditampilkan supaya
     * pertanyaan "tagihan ini dibayar lewat mana" terjawab tanpa menelusuri jurnal satu per satu —
     * dan supaya pembayaran ganda langsung terlihat sebagai dua baris di tempat yang sama.
     */
    /** @var \App\Modules\Treasury\Domain\Models\PurchaseInvoice $faktur */
    $faktur = $getRecord();
    $bayar = $faktur->payments()->with('advice:id,number,reference')->get();
@endphp

<x-filament::section heading="Pelunasan" collapsible :collapsed="$bayar->isEmpty()">
    @if ($bayar->isEmpty())
        <p class="fnb-muted">Belum ada pembayaran yang dialokasikan ke faktur ini.</p>
    @else
        <ul class="fnb-attachments">
            @foreach ($bayar as $b)
                <li>
                    <strong>{{ \App\Filament\Support\MenuFields::rupiah((string) $b->amount) }}</strong>
                    <span class="fnb-muted">
                        {{ $b->paid_on->translatedFormat('d M Y') }}
                        @if ($b->advice) · advis {{ $b->advice->number }} @endif
                        @if ($b->advice?->reference) · ref {{ $b->advice->reference }} @endif
                    </span>
                </li>
            @endforeach
        </ul>
        <p class="fnb-muted">
            Sisa hutang: {{ \App\Filament\Support\MenuFields::rupiah((string) $faktur->outstanding()->toScale(2)) }}
        </p>
    @endif
</x-filament::section>
