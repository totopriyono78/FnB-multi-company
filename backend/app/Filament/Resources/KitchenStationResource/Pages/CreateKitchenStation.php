<?php

namespace App\Filament\Resources\KitchenStationResource\Pages;

use App\Filament\Resources\KitchenStationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateKitchenStation extends CreateRecord
{
    protected static string $resource = KitchenStationResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return 'Tambah Stasiun Dapur';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Simpan Stasiun');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
