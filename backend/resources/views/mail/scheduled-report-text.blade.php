@php use App\Modules\Reporting\Application\ReportTable; @endphp
{{ $company }}
{{ $table->title }}
@foreach ($table->filters as $label => $value)
{{ $label }}: {{ $value }}
@endforeach

@foreach ($table->summary as $s)
{{ $s['label'] }}: {{ ReportTable::format($s['value'], $s['type']) }}
@endforeach

Rincian lengkap ada di lampiran ({{ strtoupper($schedule->format) }}).
Anda menerima email ini karena terdaftar pada jadwal "{{ $schedule->name }}" ({{ $frequency }}) di FnB Cloud.
