@php
    use App\Modules\Reporting\Application\MenuEngineeringReport;
    $counts = [];
    foreach ($table->rows as $row) {
        $counts[$row['class_label']] = ($counts[$row['class_label']] ?? 0) + 1;
    }
@endphp
@if ($table->rows !== [])
    <x-filament::section heading="Apa yang perlu dilakukan" description="Saran umum per kelompok; sesuaikan dengan kondisi outlet.">
        <table class="fnb-receipt" aria-label="Saran per kelompok menu">
            <thead>
                <tr>
                    <th scope="col">Kelompok</th>
                    <th scope="col" class="fnb-num">Jumlah item</th>
                    <th scope="col">Saran</th>
                </tr>
            </thead>
            <tbody>
                @foreach (MenuEngineeringReport::CLASSES as $key => $label)
                    @continue(($counts[$label] ?? 0) === 0 && $key === 'no_cost')
                    <tr>
                        <th scope="row" class="fnb-report-table__label">{{ $label }}</th>
                        <td class="fnb-num">{{ $counts[$label] ?? 0 }}</td>
                        <td>{{ MenuEngineeringReport::ADVICE[$key] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
@endif
