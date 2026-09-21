@php use App\Modules\Reporting\Application\ReportTable; @endphp
<!DOCTYPE html>
<html lang="id">
<head><meta charset="utf-8"><title>{{ $schedule->name }}</title></head>
<body style="margin:0;padding:24px;background:rgb(250,250,249);font-family:Arial,Helvetica,sans-serif;color:rgb(36,33,31);">
    <table role="presentation" width="100%" style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid rgb(231,229,228);border-radius:8px;">
        <tr><td style="padding:24px;">
            <p style="margin:0 0 4px 0;font-size:13px;color:rgb(87,83,78);">{{ $company }}</p>
            <h1 style="margin:0 0 12px 0;font-size:20px;">{{ $table->title }}</h1>
            <p style="margin:0 0 16px 0;font-size:14px;color:rgb(68,64,60);">
                @foreach ($table->filters as $label => $value){{ $label }}: <strong>{{ $value }}</strong>@if (! $loop->last) · @endif @endforeach
            </p>
            @if ($table->summary !== [])
                <table role="presentation" width="100%" style="border-collapse:collapse;margin-bottom:16px;">
                    @foreach ($table->summary as $s)
                        <tr>
                            <td style="padding:6px 0;border-bottom:1px solid rgb(245,245,244);font-size:14px;color:rgb(87,83,78);">{{ $s['label'] }}</td>
                            <td style="padding:6px 0;border-bottom:1px solid rgb(245,245,244);font-size:14px;text-align:right;font-weight:bold;">{{ ReportTable::format($s['value'], $s['type']) }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
            <p style="margin:0 0 16px 0;font-size:14px;">Rincian lengkap ada di lampiran ({{ strtoupper($schedule->format) }}).</p>
            <p style="margin:0;font-size:12px;color:rgb(104,98,93);">
                Anda menerima email ini karena terdaftar pada jadwal "{{ $schedule->name }}" ({{ $frequency }}) di FnB Cloud.
                Hubungi pembuat jadwal untuk berhenti menerima laporan ini.
            </p>
        </td></tr>
    </table>
</body>
</html>
