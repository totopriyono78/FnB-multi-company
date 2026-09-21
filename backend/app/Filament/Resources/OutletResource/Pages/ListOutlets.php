<?php

namespace App\Filament\Resources\OutletResource\Pages;

use App\Filament\Resources\OutletResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOutlets extends ListRecords
{
    protected static string $resource = OutletResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tambah Outlet')];
    }
}
