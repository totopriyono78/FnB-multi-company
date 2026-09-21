<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan Perubahan');
    }
}
