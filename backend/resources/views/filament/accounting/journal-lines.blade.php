@php
    use App\Filament\Support\MenuFields;
    $lines = $getRecord()->lines()->with(['account', 'outlet'])->get();
    $debit = $lines->sum(fn ($l) => (float) $l->debit);
    $credit = $lines->sum(fn ($l) => (float) $l->credit);
@endphp
<x-filament::section heading="Baris jurnal">
    <div class="fnb-table-scroll" tabindex="0" role="region" aria-label="Baris jurnal (dapat digulir)">
        <table class="fnb-receipt fnb-report-table" aria-label="Baris jurnal">
            <thead>
                <tr>
                    <th scope="col">Akun</th>
                    <th scope="col">Outlet</th>
                    <th scope="col">Catatan</th>
                    <th scope="col" class="fnb-num">Debit</th>
                    <th scope="col" class="fnb-num">Kredit</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <th scope="row" class="fnb-report-table__label">{{ $line->account?->label() ?? '—' }}</th>
                        <td>{{ $line->outlet?->name ?? '—' }}</td>
                        <td>{{ $line->memo ?? '—' }}</td>
                        <td class="fnb-num">{{ (float) $line->debit > 0 ? MenuFields::rupiah((string) $line->debit) : '' }}</td>
                        <td class="fnb-num">{{ (float) $line->credit > 0 ? MenuFields::rupiah((string) $line->credit) : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row">TOTAL</th>
                    <td></td>
                    <td></td>
                    <td class="fnb-num">{{ MenuFields::rupiah((string) $debit) }}</td>
                    <td class="fnb-num">{{ MenuFields::rupiah((string) $credit) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-filament::section>
