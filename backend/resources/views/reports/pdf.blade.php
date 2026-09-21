@php
    use App\Modules\Reporting\Application\ReportTable;
    $cols = $table->columns;
    $dense = count($cols) > 10;
    $veryDense = count($cols) > 14;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $table->title }}</title>
    <style>
        /* Warna mengikuti token tema back-office (gray & primary). */
        @page { margin: 16mm 12mm 14mm 12mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: {{ $veryDense ? '6pt' : ($dense ? '7pt' : '8.5pt') }}; color: rgb(36, 33, 31); }
        h1 { font-size: 13pt; margin: 0 0 2pt 0; }
        .company { font-size: 9pt; font-weight: bold; margin-bottom: 6pt; }
        .meta { color: rgb(87, 83, 78); margin: 0 0 8pt 0; }
        .meta span { margin-right: 12pt; }
        .summary { width: 100%; border-collapse: collapse; margin-bottom: 10pt; }
        .summary td { border: 0.5pt solid rgb(214, 211, 209); padding: 4pt 6pt; width: 25%; vertical-align: top; }
        .summary .k { color: rgb(87, 83, 78); display: block; }
        .summary .v { font-weight: bold; font-size: 10pt; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: rgb(245, 245, 244); text-align: left; padding: {{ $veryDense ? '2pt 2pt' : '3pt 4pt' }}; white-space: normal; border-bottom: 0.75pt solid rgb(168, 162, 158); font-weight: bold; }
        table.data th.num { text-align: right; white-space: normal; }
        table.data td { padding: {{ $veryDense ? '2pt 2pt' : '3pt 4pt' }}; border-bottom: 0.5pt solid rgb(231, 229, 228); vertical-align: top; }
        table.data .num { text-align: right; white-space: nowrap; }
        table.data tfoot td { font-weight: bold; border-top: 0.75pt solid rgb(168, 162, 158); border-bottom: none; }
        .notes { margin-top: 10pt; color: rgb(87, 83, 78); }
        .notes p { margin: 0 0 2pt 0; }
        .empty { padding: 12pt 0; color: rgb(87, 83, 78); }
        .footer { position: fixed; bottom: -8mm; left: 0; right: 0; color: rgb(104, 98, 93); font-size: 7pt; }
    </style>
</head>
<body>
    <div class="footer">{{ $company }} · {{ $table->title }} · dibuat {{ $generatedAt }}</div>
    <h1>{{ $table->title }}</h1>
    <div class="company">{{ $company }}</div>
    <p class="meta">
        @foreach ($table->filters as $label => $value)
            <span>{{ $label }}: {{ $value }}</span>
        @endforeach
    </p>

    @if ($table->summary !== [])
        <table class="summary">
            @foreach (array_chunk($table->summary, 4) as $chunk)
                <tr>
                    @foreach ($chunk as $s)
                        <td><span class="k">{{ $s['label'] }}</span><span class="v">{{ ReportTable::format($s['value'], $s['type']) }}</span></td>
                    @endforeach
                    @for ($i = count($chunk); $i < 4; $i++)
                        <td></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    @endif

    @if ($table->rows === [])
        <p class="empty">Tidak ada data pada periode ini.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    @foreach ($cols as $col)
                        <th class="{{ $col['type'] === ReportTable::TEXT ? '' : 'num' }}">{{ $col['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($table->rows as $row)
                    <tr>
                        @foreach ($cols as $key => $col)
                            <td class="{{ $col['type'] === ReportTable::TEXT ? '' : 'num' }}">{{ ReportTable::format($row[$key] ?? null, $col['type']) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            @if ($table->totals !== null)
                <tfoot>
                    <tr>
                        @foreach ($cols as $key => $col)
                            @php $v = $table->totals[$key] ?? null; @endphp
                            <td class="{{ $col['type'] === ReportTable::TEXT ? '' : 'num' }}">{{ $v === null ? '' : ($col['type'] === ReportTable::TEXT ? $v : ReportTable::format($v, $col['type'])) }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    @endif

    @if ($table->notes !== [])
        <div class="notes">
            @foreach ($table->notes as $note)
                <p>{{ $note }}</p>
            @endforeach
        </div>
    @endif
</body>
</html>
