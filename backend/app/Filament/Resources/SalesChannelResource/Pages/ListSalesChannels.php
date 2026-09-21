<?php

namespace App\Filament\Resources\SalesChannelResource\Pages;

use App\Filament\Resources\SalesChannelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalesChannels extends ListRecords
{
    protected static string $resource = SalesChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tambah Channel')];
    }
}
