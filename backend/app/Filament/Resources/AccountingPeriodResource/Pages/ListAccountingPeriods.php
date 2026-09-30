<?php

namespace App\Filament\Resources\AccountingPeriodResource\Pages;

use App\Filament\Resources\AccountingPeriodResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountingPeriods extends ListRecords
{
    protected static string $resource = AccountingPeriodResource::class;

    public function getSubheading(): ?string
    {
        return 'Menutup periode mengunci posting ke bulan itu. Tutup buku tahunan belum tersedia di putaran ini.';
    }
}
