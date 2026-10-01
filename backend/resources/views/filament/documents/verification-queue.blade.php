@php
    use App\Filament\Resources\JournalResource;
    use App\Filament\Resources\PaymentAdviceResource;
    use App\Filament\Resources\PaymentRequestResource;
    use App\Filament\Support\MenuFields;
    use App\Modules\Accounting\Domain\Models\Journal;
    use App\Modules\Documents\Application\ApprovalMatrix;

    $queue = $this->queue();
    $saya = $this->viewer();
    $sla = $queue->slaDays();
    $peran = ApprovalMatrix::roleOptions();

    $tandaTangan = $saya === null ? [] : $queue->awaitingMySignature($saya);
    $jurnal = $saya === null ? [] : $queue->journalsAwaitingPosting($saya);
    $tertahan = $queue->stalled();
    $belumBuku = $queue->advicesNotBooked();
    $belumBayar = $queue->approvedUnpaid();
    $kosong = $tandaTangan === [] && $jurnal === [] && $tertahan === [] && $belumBuku === [] && $belumBayar === [];

    // "0 hari" terbaca seperti data yang belum terisi; dokumen yang baru masuk pagi ini memang
    // belum berumur, dan itu lebih jelas dikatakan sebagai "hari ini".
    $umur = fn (int $hari) => $hari === 0 ? 'hari ini' : $hari.' hari';
@endphp

<x-filament-panels::page>
    @if ($kosong)
        <x-filament::section>
            <p>Tidak ada yang menunggu diperiksa. Dokumen yang baru diajukan akan muncul di sini.</p>
            <p class="fnb-muted">Sebuah dokumen disebut tertahan setelah {{ $sla }} hari tanpa tanda tangan.</p>
        </x-filament::section>
    @endif

    {{-- Menunggu saya: satu-satunya bagian yang isinya dihitung dari peran orang yang sedang masuk. --}}
    @if ($tandaTangan !== [])
        <x-filament::section heading="Menunggu tanda tangan Anda" :description="count($tandaTangan).' dokumen'">
            <ul class="fnb-queue">
                @foreach ($tandaTangan as $item)
                    @php $sppk = $item['record']; @endphp
                    <li @class(['fnb-queue__item', 'fnb-queue__item--late' => $item['late']])>
                        <a href="{{ PaymentRequestResource::getUrl('view', ['record' => $sppk->id]) }}">
                            {{ $sppk->number }} · {{ $sppk->payee_name }} · {{ MenuFields::rupiah((string) $sppk->amount) }}
                        </a>
                        <span class="fnb-muted">
                            tanda tangan tingkat {{ $item['level'] }} dari {{ $sppk->required_levels }}
                            ({{ $peran[$item['role']] ?? $item['role'] }})
                            · diajukan {{ $sppk->requester?->name }}
                            @if ($sppk->outlet) · {{ $sppk->outlet->name }} @endif
                            · {{ $umur($item['age']) }}
                            @if ($item['late']) · <strong>lewat tenggat {{ $sla }} hari</strong> @endif
                        </span>
                        <span class="fnb-muted">{{ $sppk->description }}</span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    @if ($jurnal !== [])
        <x-filament::section heading="Jurnal menunggu diposting" :description="count($jurnal).' jurnal diajukan orang lain'">
            <ul class="fnb-queue">
                @foreach ($jurnal as $item)
                    @php $j = $item['record']; @endphp
                    <li @class(['fnb-queue__item', 'fnb-queue__item--late' => $item['late']])>
                        <a href="{{ JournalResource::getUrl('view', ['record' => $j->id]) }}">
                            {{ $j->number }} · {{ MenuFields::rupiah((string) ($j->total ?? '0')) }}
                        </a>
                        <span class="fnb-muted">
                            {{ $j->journal_date->translatedFormat('d M Y') }}
                            · diajukan {{ $j->submitter?->name }}
                            · {{ $umur($item['age']) }}
                            @if ($item['late']) · <strong>lewat tenggat {{ $sla }} hari</strong> @endif
                        </span>
                        <span class="fnb-muted">{{ $j->description }}</span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    {{-- Uang sudah keluar, pembukuannya belum. Inilah bagian yang paling mudah terlewat dan paling
         mahal akibatnya: selisih yang baru ketemu saat rekonsiliasi bank. --}}
    @if ($belumBuku !== [])
        <x-filament::section heading="Advis bayar belum terbukukan"
            description="Pembayaran sudah diinstruksikan, jurnalnya belum diposting.">
            <ul class="fnb-queue">
                @foreach ($belumBuku as $item)
                    @php $a = $item['record']; @endphp
                    <li @class(['fnb-queue__item', 'fnb-queue__item--late' => $item['late']])>
                        <a href="{{ PaymentAdviceResource::getUrl('view', ['record' => $a->id]) }}">
                            {{ $a->number }} · {{ MenuFields::rupiah((string) $a->amount) }}
                        </a>
                        <span class="fnb-muted">
                            dibayar {{ $a->paid_on->translatedFormat('d M Y') }}
                            · atas {{ $a->request?->number }} ({{ $a->request?->payee_name }})
                            · jurnal {{ $a->journal?->number }}
                            ({{ Journal::STATUS_LABEL[$a->journal?->status] ?? '-' }})
                            · {{ $umur($item['age']) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    @if ($belumBayar !== [])
        <x-filament::section heading="Disetujui, belum dibayar" :description="count($belumBayar).' pengajuan'">
            <ul class="fnb-queue">
                @foreach ($belumBayar as $item)
                    @php $sppk = $item['record']; @endphp
                    <li @class(['fnb-queue__item', 'fnb-queue__item--late' => $item['late']])>
                        <a href="{{ PaymentRequestResource::getUrl('view', ['record' => $sppk->id]) }}">
                            {{ $sppk->number }} · {{ $sppk->payee_name }}
                            · sisa {{ MenuFields::rupiah((string) $sppk->outstanding()->toScale(2)) }}
                        </a>
                        <span class="fnb-muted">
                            @if ($sppk->due_date)
                                jatuh tempo {{ $sppk->due_date->translatedFormat('d M Y') }}
                                @if ($item['late']) · <strong>terlambat {{ $item['age'] }} hari</strong> @endif
                            @else
                                tanpa jatuh tempo · disetujui {{ $item['age'] === 0 ? 'hari ini' : $item['age'].' hari lalu' }}
                            @endif
                            @if ($sppk->outlet) · {{ $sppk->outlet->name }} @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    {{-- Tertahan ditampilkan paling bawah dan kepada semua yang berhak melihat: dokumen macet
         biasanya macet karena tidak ada yang merasa itu bagiannya. --}}
    @if ($tertahan !== [])
        <x-filament::section heading="Tertahan lebih dari {{ $sla }} hari" collapsible
            description="Menunggu tanda tangan orang lain. Ditampilkan agar tidak ada yang diam-diam mengendap.">
            <ul class="fnb-queue">
                @foreach ($tertahan as $item)
                    @php $sppk = $item['record']; @endphp
                    <li class="fnb-queue__item fnb-queue__item--late">
                        <a href="{{ PaymentRequestResource::getUrl('view', ['record' => $sppk->id]) }}">
                            {{ $sppk->number }} · {{ $sppk->payee_name }} · {{ MenuFields::rupiah((string) $sppk->amount) }}
                        </a>
                        <span class="fnb-muted">
                            {{ $sppk->approvals_count }} dari {{ $sppk->required_levels }} tanda tangan
                            · diajukan {{ $sppk->requester?->name }}
                            · {{ $umur($item['age']) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
