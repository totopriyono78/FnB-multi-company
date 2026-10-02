@php
    /*
     * Pelunasan yang sudah diterima atas tagihan ini, berikut rekening penerimanya. Rekeningnya ikut
     * ditampilkan karena itulah yang dicari saat rekonsiliasi bank: uang ini masuk ke mana.
     */
    /** @var \App\Modules\Treasury\Domain\Models\SalesInvoice $tagihan */
    $tagihan = $getRecord();
    $terima = $tagihan->receipts()->with(['cashAccount:id,name,code,kind,bank_name,account_number', 'journal:id,number,status'])->get();
    $label = \App\Modules\Accounting\Domain\Models\Journal::STATUS_LABEL;
@endphp

<x-filament::section heading="Pelunasan diterima" collapsible :collapsed="$terima->isEmpty()">
    @if ($terima->isEmpty())
        <p class="fnb-muted">Belum ada pelunasan yang diterima untuk tagihan ini.</p>
    @else
        <ul class="fnb-attachments">
            @foreach ($terima as $t)
                <li>
                    <strong>{{ $t->number }}</strong>
                    — {{ \App\Filament\Support\MenuFields::rupiah((string) $t->amount) }}
                    <span class="fnb-muted">
                        · {{ $t->received_on->translatedFormat('d M Y') }}
                        · masuk {{ $t->cashAccount?->label() }}
                        @if ($t->reference) · ref {{ $t->reference }} @endif
                        @if ($t->journal) · jurnal {{ $t->journal->number }} ({{ $label[$t->journal->status] ?? $t->journal->status }}) @endif
                    </span>
                </li>
            @endforeach
        </ul>
        <p class="fnb-muted">
            Sisa piutang: {{ \App\Filament\Support\MenuFields::rupiah((string) $tagihan->outstanding()->toScale(2)) }}
        </p>
    @endif
</x-filament::section>
