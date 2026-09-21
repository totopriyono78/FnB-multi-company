<?php

namespace App\Filament\Resources\KitchenStationResource\Pages;

use App\Filament\Resources\KitchenStationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKitchenStations extends ListRecords
{
    protected static string $resource = KitchenStationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tambah Stasiun')];
    }
}
