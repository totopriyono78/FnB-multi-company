<?php

namespace App\Filament\Resources\StockBalanceResource\Pages;

use App\Filament\Resources\StockAdjustmentResource;
use App\Filament\Resources\StockBalanceResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListStockBalances extends ListRecords
{
    protected static string $resource = StockBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('adjust')->label('Catat Penyesuaian')->icon('heroicon-m-plus')
                ->url(StockAdjustmentResource::getUrl('create'))
                ->visible(StockAdjustmentResource::canCreate()),
        ];
    }
}
