<?php

namespace App\Filament\Resources\MenuCategoryResource\Pages;

use App\Filament\Resources\MenuCategoryResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditMenuCategory extends EditRecord
{
    protected static string $resource = MenuCategoryResource::class;

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan Perubahan');
    }
}
