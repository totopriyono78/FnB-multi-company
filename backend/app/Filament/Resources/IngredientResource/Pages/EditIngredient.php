<?php

namespace App\Filament\Resources\IngredientResource\Pages;

use App\Filament\Resources\IngredientResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditIngredient extends EditRecord
{
    protected static string $resource = IngredientResource::class;

    public function getTitle(): string
    {
        return 'Ubah Bahan';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['base_unit'], $data['kind']);

        return $data;
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan Perubahan');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
