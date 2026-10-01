@php use App\Modules\Reporting\Application\ReportTable; @endphp
<x-filament-panels::page>
    @php $sudahAda = $this->existingJournal(); @endphp

    @if ($sudahAda !== null)
        <div class="fnb-callout fnb-callout--danger" role="alert">
            <p class="fnb-callout__title">Saldo awal untuk tanggal ini sudah ada</p>
            <p>Jurnal {{ $sudahAda->number }} ({{ \App\Modules\Accounting\Domain\Models\Journal::STATUS_LABEL[$sudahAda->status] ?? $sudahAda->status }}).
               Mengimpor lagi akan menggandakan seluruh saldo — hapus atau balik jurnal itu dulu bila hendak menggantinya.</p>
        </div>
    @endif

    <form wire:submit="check" class="fnb-stack">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit" icon="heroicon-m-magnifying-glass" color="gray">
                Periksa berkas
            </x-filament::button>
        </div>
    </form>

    @if ($preview !== null)
        @if ($preview['problems'] !== [])
            <div class="fnb-callout fnb-callout--danger" role="alert">
                <p class="fnb-callout__title">Berkas belum bisa diimpor</p>
                <ul class="fnb-callout__list">
                    @foreach ($preview['problems'] as $masalah)
                        <li>{{ $masalah }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($preview['rows'] !== [])
            <x-filament::section heading="Saldo awal yang akan dicatat"
                :description="count($preview['rows']).' akun · debit '.ReportTable::rupiah($preview['debit']).' · kredit '.ReportTable::rupiah($preview['credit'])">
                <div class="fnb-table-scroll" tabindex="0" role="region" aria-label="Saldo awal (dapat digulir)">
                    <table class="fnb-receipt fnb-report-table" aria-label="Saldo awal">
                        <thead>
                            <tr>
                                <th scope="col">Akun</th>
                                <th scope="col" class="fnb-num">Debit</th>
                                <th scope="col" class="fnb-num">Kredit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($preview['rows'] as $baris)
                                <tr>
                                    <th scope="row" class="fnb-report-table__label">{{ $baris['code'] }} — {{ $baris['name'] }}</th>
                                    <td class="fnb-num">{{ ReportTable::format($baris['debit'], ReportTable::MONEY) }}</td>
                                    <td class="fnb-num">{{ ReportTable::format($baris['credit'], ReportTable::MONEY) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="row">TOTAL</th>
                                <td class="fnb-num">{{ ReportTable::rupiah($preview['debit']) }}</td>
                                <td class="fnb-num">{{ ReportTable::rupiah($preview['credit']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
