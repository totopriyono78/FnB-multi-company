<?php

namespace App\Filament\Resources\KitchenStationResource\Pages;

use App\Filament\Resources\KitchenStationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditKitchenStation extends EditRecord
{
    protected static string $resource = KitchenStationResource::class;

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan Perubahan');
    }
}
