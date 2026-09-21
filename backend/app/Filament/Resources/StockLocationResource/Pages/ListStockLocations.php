<?php

namespace App\Filament\Resources\StockLocationResource\Pages;

use App\Filament\Resources\StockLocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockLocations extends ListRecords
{
    protected static string $resource = StockLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tambah Lokasi')];
    }
}
