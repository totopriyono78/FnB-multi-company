@php use App\Modules\Reporting\Application\ReportTable; @endphp
<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php $table = $this->outstanding(); @endphp

    <x-filament::section :heading="$table->title" :description="$table->subtitle">
        @if ($table->rows === [])
            <p class="fnb-muted">Belum ada pembayaran non-tunai pada atau sebelum tanggal ini.</p>
        @else
            <div class="fnb-table-scroll" tabindex="0" role="region" aria-label="{{ $table->title }} (dapat digulir)">
                <table class="fnb-receipt fnb-report-table" aria-label="{{ $table->title }}">
                    <thead>
                        <tr>
                            @foreach ($table->columns as $col)
                                <th scope="col" @class(['fnb-num' => $col['type'] !== ReportTable::TEXT])>{{ $col['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($table->rows as $row)
                            <tr>
                                @foreach ($table->columns as $key => $col)
                                    @if ($loop->first)
                                        <th scope="row" class="fnb-report-table__label">{{ $row[$key] }}</th>
                                    @else
                                        <td @class([
                                            'fnb-num' => true,
                                            'fnb-negative' => str_starts_with((string) $row[$key], '-'),
                                        ])>{{ ReportTable::format($row[$key], $col['type']) }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($table->totals !== null)
                        <tfoot>
                            <tr>
                                @foreach ($table->columns as $key => $col)
                                    @if ($loop->first)
                                        <th scope="row">{{ $table->totals[$key] }}</th>
                                    @else
                                        <td class="fnb-num">{{ ReportTable::format($table->totals[$key] ?? null, $col['type']) }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        @endif

        @if ($table->notes !== [])
            <ul class="fnb-report-notes">
                @foreach ($table->notes as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
