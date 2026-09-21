@php
    /** @var \App\Modules\Inventory\Domain\Models\StockCount $count */
    $count = $getRecord();
    $blind = $count->status === \App\Modules\Inventory\Domain\Models\StockCount::COUNTING;
    $qty = fn ($v, $unit) => \App\Filament\Support\InventoryFields::qtyWithUnit((string) $v, $unit);
    $rp = fn ($v) => \App\Filament\Support\MenuFields::rupiah((string) $v);
@endphp

<div class="fnb-table-scroll">
    <table class="fnb-receipt" aria-label="Hasil hitung stock opname">
        <thead>
            <tr>
                <th scope="col">Bahan</th>
                @unless ($blind)
                    <th scope="col" class="fnb-num">Sistem</th>
                @endunless
                <th scope="col" class="fnb-num">Fisik</th>
                @unless ($blind)
                    <th scope="col" class="fnb-num">Selisih</th>
                    <th scope="col" class="fnb-num">Nilai</th>
                @endunless
                <th scope="col">Catatan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($count->lines as $line)
                @php
                    $unit = $line->ingredient->base_unit;
                    $diff = $line->difference;
                @endphp
                <tr>
                    <td>{{ $line->ingredient->name }}</td>
                    @unless ($blind)
                        <td class="fnb-num">{{ $qty($line->system_qty, $unit) }}</td>
                    @endunless
                    <td class="fnb-num">{{ $line->counted_qty === null ? 'Belum dihitung' : $qty($line->counted_qty, $unit) }}</td>
                    @unless ($blind)
                        <td @class(['fnb-num', 'fnb-negative' => $diff !== null && (float) $diff < 0])>
                            {{ $diff === null ? '-' : ((float) $diff > 0 ? '+' : '').$qty($diff, $unit) }}
                        </td>
                        <td class="fnb-num">{{ $line->variance_value === null ? '-' : $rp($line->variance_value) }}</td>
                    @endunless
                    <td>{{ $line->note ?: '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
