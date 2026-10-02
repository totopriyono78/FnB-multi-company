@php
    /*
     * Baris faktur beserta akun yang didebit. Akunnya ikut ditampilkan karena di situlah satu-satunya
     * keputusan akuntansi sebuah faktur berada: pembelian ini masuk persediaan, beban, atau uang muka.
     */
    /** @var \App\Modules\Treasury\Domain\Models\PurchaseInvoice $faktur */
    $faktur = $getRecord();
    $baris = $faktur->lines()->with('account:id,code,name')->get();
@endphp

<x-filament::section heading="Baris faktur">
    <div class="fnb-table-scroll" tabindex="0" role="region" aria-label="Baris faktur (dapat digulir)">
        <table class="fnb-receipt fnb-report-table" aria-label="Baris faktur">
            <thead>
                <tr>
                    <th scope="col">Keterangan</th>
                    <th scope="col">Akun</th>
                    <th scope="col" class="fnb-num">Jumlah</th>
                    <th scope="col" class="fnb-num">Harga satuan</th>
                    <th scope="col" class="fnb-num">Nilai</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($baris as $b)
                    <tr>
                        <th scope="row" class="fnb-report-table__label">{{ $b->description }}</th>
                        <td>{{ $b->account->label() }}</td>
                        <td class="fnb-num">{{ rtrim(rtrim(number_format((float) $b->quantity, 4, ',', '.'), '0'), ',') }}</td>
                        <td class="fnb-num">{{ \App\Filament\Support\MenuFields::rupiah((string) $b->unit_price) }}</td>
                        <td class="fnb-num">{{ \App\Filament\Support\MenuFields::rupiah((string) $b->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row" colspan="4">DPP</th>
                    <td class="fnb-num">{{ \App\Filament\Support\MenuFields::rupiah((string) $faktur->subtotal) }}</td>
                </tr>
                @if ($faktur->has_tax_invoice)
                    <tr>
                        <th scope="row" colspan="4">PPN masukan</th>
                        <td class="fnb-num">{{ \App\Filament\Support\MenuFields::rupiah((string) $faktur->tax_amount) }}</td>
                    </tr>
                @endif
                <tr class="fnb-report-row--result">
                    <th scope="row" colspan="4">JUMLAH TAGIHAN</th>
                    <td class="fnb-num">{{ \App\Filament\Support\MenuFields::rupiah((string) $faktur->total) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-filament::section>
