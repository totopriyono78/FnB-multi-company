<?php

namespace App\Filament\Resources\StockCountResource\Pages;

use App\Filament\Resources\StockCountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockCounts extends ListRecords
{
    protected static string $resource = StockCountResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Mulai Opname')];
    }
}
