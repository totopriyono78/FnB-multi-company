<?php

namespace App\Filament\Resources\SalesChannelResource\Pages;

use App\Filament\Resources\SalesChannelResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditSalesChannel extends EditRecord
{
    protected static string $resource = SalesChannelResource::class;

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan Perubahan');
    }
}
