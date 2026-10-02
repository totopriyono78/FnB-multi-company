@php use App\Modules\Reporting\Application\ReportTable; @endphp
<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php $table = $this->table(); @endphp

    @if ($table === null)
        <x-filament::section>
            <p class="fnb-muted">{{ $this->emptyHint() }}</p>
        </x-filament::section>
    @else
        @if ($table->summary !== [])
            <dl class="fnb-report-summary" aria-label="Ringkasan laporan">
                @foreach ($table->summary as $s)
                    <div class="fnb-report-summary__item">
                        <dt>{{ $s['label'] }}</dt>
                        <dd @class(['fnb-report-summary__negative' => str_starts_with((string) $s['value'], '-')])>{{ ReportTable::format($s['value'], $s['type']) }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        <x-filament::section :heading="$table->title" :description="$table->subtitle">
            @if ($table->filters !== [])
                <p class="fnb-muted">
                    @foreach ($table->filters as $label => $value) {{ $label }}: {{ $value }} @endforeach
                </p>
            @endif

            @if ($table->rows === [])
                <p class="fnb-muted">{{ $this->emptyRowsHint() }}</p>
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
                                {{-- Laporan keuangan menandai barisnya: seksi, subtotal, hasil. Tanpa itu
                                     neraca terbaca sebagai satu dinding angka tanpa awal dan akhir. --}}
                                @php $style = $row['_style'] ?? null; @endphp
                                <tr @class(['fnb-report-row--'.$style => $style !== null])>
                                    @foreach ($table->columns as $key => $col)
                                        @php $value = $row[$key] ?? null; @endphp
                                        @if ($loop->first)
                                            <th scope="row" class="fnb-report-table__label">{{ ReportTable::format($value, $col['type']) }}</th>
                                        @else
                                            <td @class([
                                                'fnb-num' => $col['type'] !== ReportTable::TEXT,
                                                'fnb-negative' => $col['type'] === ReportTable::MONEY && str_starts_with((string) $value, '-'),
                                            ])>{{-- Baris judul kelompok tidak punya angka; "-" di sana dibaca sebagai data yang hilang. --}}{{ $style === 'section' ? '' : ReportTable::format($value, $col['type']) }}</td>
                                        @endif
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                        @if ($table->totals !== null)
                            <tfoot>
                                <tr>
                                    @foreach ($table->columns as $key => $col)
                                        @php $value = $table->totals[$key] ?? null; @endphp
                                        @if ($loop->first)
                                            <th scope="row">{{ $value }}</th>
                                        @else
                                            <td @class(['fnb-num' => $col['type'] !== ReportTable::TEXT])>{{ $value === null || $value === '' ? '' : ($col['type'] === ReportTable::TEXT ? $value : ReportTable::format($value, $col['type'])) }}</td>
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
    @endif
</x-filament-panels::page>
