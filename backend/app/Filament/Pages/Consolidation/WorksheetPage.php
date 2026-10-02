<?php

namespace App\Filament\Pages\Consolidation;

use App\Modules\Consolidation\Application\ConsolidationReports;
use App\Modules\Consolidation\Domain\Models\ConsolidationRun;
use App\Modules\Reporting\Application\ReportTable;

/**
 * Kertas kerja konsolidasi (CON-06).
 *
 * Satu kolom per entitas, lalu Jumlah, Eliminasi, dan Konsolidasi. Dua baris terakhirnya — laba
 * tiap entitas dan baris pemeriksaan yang harus nol — adalah alasan halaman ini ada: kolom entitas
 * yang tidak nol di baris pemeriksaan langsung menunjuk entitas yang bukunya timpang, dan tanpa itu
 * satu-satunya cara mencarinya adalah membuka buku tiap entitas satu per satu.
 */
class WorksheetPage extends ConsolidationReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Kertas Kerja Konsolidasi';

    protected static ?string $title = 'Kertas Kerja Konsolidasi';

    protected static ?string $slug = 'konsolidasi/kertas-kerja';

    protected static ?int $navigationSort = 2;

    protected function buildFor(ConsolidationRun $run): ReportTable
    {
        return app(ConsolidationReports::class)->worksheet($run);
    }
}
