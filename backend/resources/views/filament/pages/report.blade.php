@php
    use App\Modules\Reporting\Application\ReportTable;
    $error = $this->filterError();
    $table = $error === null ? $this->reportTable() : null;
    $barColumns = ['share', 'menu_mix'];
@endphp

<x-filament-panels::page>
    <div class="fnb-report-toolbar">
        {{ $this->form }}
    </div>

    @if ($error !== null)
        <div class="fnb-callout fnb-callout--danger" role="alert">
            <p class="fnb-callout__title">Laporan belum dapat ditampilkan</p>
            <p>{{ $error }}</p>
        </div>
    @elseif ($table !== null)
        <p class="fnb-muted fnb-report-filters">
            @foreach ($table->filters as $label => $value)
                <span>{{ $label }}: <strong>{{ $value }}</strong></span>
            @endforeach
        </p>

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
            @if ($table->rows === [])
                <p class="fnb-muted">Tidak ada data pada periode dan cakupan ini.</p>
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
                                        @php $value = $row[$key] ?? null; @endphp
                                        @if ($loop->first)
                                            <th scope="row" class="fnb-report-table__label">{{ ReportTable::format($value, $col['type']) }}</th>
                                        @elseif (in_array($key, $barColumns, true) && $value !== null)
                                            <td class="fnb-num">
                                                <span class="fnb-report-bar" aria-hidden="true"><span style="width: {{ max(0, min(100, (float) $value)) }}%"></span></span>
                                                {{ ReportTable::format($value, $col['type']) }}
                                            </td>
                                        @else
                                            <td @class([
                                                'fnb-num' => $col['type'] !== ReportTable::TEXT,
                                                'fnb-negative' => $col['type'] === ReportTable::MONEY && str_starts_with((string) $value, '-'),
                                            ])>{{ ReportTable::format($value, $col['type']) }}</td>
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
                                            <td @class(['fnb-num' => $col['type'] !== ReportTable::TEXT])>{{ $value === null ? '' : ($col['type'] === ReportTable::TEXT ? $value : ReportTable::format($value, $col['type'])) }}</td>
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

        @if ($this->extraView())
            @include($this->extraView())
        @endif
    @endif
</x-filament-panels::page>
