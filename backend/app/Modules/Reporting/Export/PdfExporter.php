<?php

namespace App\Modules\Reporting\Export;

use App\Modules\Reporting\Application\ReportAccess;
use App\Modules\Reporting\Application\ReportTable;
use Barryvdh\DomPDF\Facade\Pdf;

/** Ekspor ReportTable ke PDF (FR-RPT-08) memakai tampilan cetak sederhana. */
class PdfExporter
{
    public function write(ReportTable $table, string $path, string $companyName): void
    {
        $landscape = count($table->columns) > 6;
        Pdf::loadView('reports.pdf', [
            'table' => $table,
            'company' => $companyName,
            'generatedAt' => now()->setTimezone(ReportAccess::timezone())->format('d/m/Y H.i'),
        ])
            ->setPaper('a4', $landscape ? 'landscape' : 'portrait')
            ->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isFontSubsettingEnabled' => true, 'defaultFont' => 'DejaVu Sans'])
            ->save($path);
    }
}
