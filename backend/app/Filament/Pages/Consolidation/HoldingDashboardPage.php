<?php

namespace App\Filament\Pages\Consolidation;

use App\Modules\Consolidation\Application\HoldingDashboard;
use App\Modules\Consolidation\Domain\Models\ConsolidationRun;
use App\Modules\Reporting\Application\ReportTable;

/**
 * Dasbor holding (CON-09): kesiapan tutup buku tiap entitas.
 *
 * Halaman pertama yang dibuka konsolidator setiap pagi, dan satu-satunya yang menjawab pertanyaan
 * "entitas mana yang menahan tutup buku bulan ini" tanpa harus menghubungi sebelas cabang.
 */
class HoldingDashboardPage extends ConsolidationReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Dasbor Holding';

    protected static ?string $title = 'Dasbor Holding';

    protected static ?string $slug = 'konsolidasi/dasbor';

    protected static ?int $navigationSort = 1;

    public function emptyRowsHint(): string
    {
        return 'Belum ada entitas yang ditarik pada proses ini. Jalankan "Tarik saldo entitas" lebih dulu.';
    }

    protected function buildFor(ConsolidationRun $run): ReportTable
    {
        return app(HoldingDashboard::class)->build($run);
    }
}
