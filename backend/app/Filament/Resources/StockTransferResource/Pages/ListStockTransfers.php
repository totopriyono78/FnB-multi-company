<?php

namespace App\Filament\Resources\StockTransferResource\Pages;

use App\Filament\Resources\StockTransferResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockTransfers extends ListRecords
{
    protected static string $resource = StockTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Kirim Stok')];
    }
}
