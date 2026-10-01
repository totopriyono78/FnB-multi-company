@php
    /*
     * Pembayaran yang sudah diinstruksikan atas SPPK ini, berikut jurnalnya. Nomor jurnal dan
     * statusnya ikut ditampilkan: "sudah dibayar" dan "sudah terbukukan" adalah dua hal berbeda, dan
     * yang memeriksa pengeluaran perlu melihat keduanya di satu tempat.
     */
    /** @var \App\Modules\Documents\Domain\Models\PaymentRequest $sppk */
    $sppk = $getRecord();
    $advis = $sppk->advices()->with(['bankAccount:id,code,name', 'journal:id,number,status'])->get();
    $label = \App\Modules\Accounting\Domain\Models\Journal::STATUS_LABEL;
@endphp

<x-filament::section heading="Advis bayar" collapsible :collapsed="$advis->isEmpty()">
    @if ($advis->isEmpty())
        <p class="fnb-muted">Belum ada pembayaran yang diterbitkan untuk pengajuan ini.</p>
    @else
        <ul class="fnb-attachments">
            @foreach ($advis as $a)
                <li>
                    <strong>{{ $a->number }}</strong>
                    — {{ \App\Filament\Support\MenuFields::rupiah((string) $a->amount) }}
                    <span class="fnb-muted">
                        · {{ $a->paid_on->translatedFormat('d M Y') }}
                        · dari {{ $a->bankAccount?->label() }}
                        @if ($a->reference) · ref {{ $a->reference }} @endif
                        @if ($a->journal) · jurnal {{ $a->journal->number }} ({{ $label[$a->journal->status] ?? $a->journal->status }}) @endif
                        @if ($a->note) · {{ $a->note }} @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament::section>
