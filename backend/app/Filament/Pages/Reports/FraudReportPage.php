<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Support\ReportPage;
use App\Modules\Reporting\Application\ReportAccess;

/** Void, refund, diskon manual, ubah harga, buka laci, dan selisih kas per pengguna (FR-RPT-04). */
class FraudReportPage extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationLabel = 'Anti-Fraud';

    protected static ?string $title = 'Laporan Anti-Fraud';

    protected static ?string $slug = 'laporan/anti-fraud';

    protected static ?int $navigationSort = 3;

    protected static function kind(): string
    {
        return ReportAccess::SALES;
    }

    protected function reportKey(): string
    {
        return $this->variant === 'events' ? 'fraud.events' : 'fraud';
    }

    protected function variants(): array
    {
        return ['users' => 'Ringkasan per pengguna', 'events' => 'Rincian kejadian'];
    }
}
